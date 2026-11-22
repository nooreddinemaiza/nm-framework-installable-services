(function () {
    function renderResult(item, className, root, status) {
        var li = document.createElement('li');
        li.className = className;
        var link = document.createElement('a');
        link.href = item.url;
        var title = document.createElement('span');
        title.className = className + '__title';
        title.textContent = item.title;
        link.appendChild(title);
        if (item.excerpt) {
            var excerpt = document.createElement('span');
            excerpt.className = className + '__excerpt';
            excerpt.textContent = item.excerpt;
            link.appendChild(excerpt);
        }
        li.appendChild(link);

        if (Array.isArray(item.actions) && item.actions.length > 0) {
            var actions = document.createElement('div');
            actions.className = className + '__actions';
            item.actions.forEach(function (action) {
                if (!action || typeof action.name !== 'string' || typeof action.action !== 'string') return;
                var button = document.createElement('button');
                button.type = 'button';
                button.className = className + '__action';
                button.textContent = action.name;
                button.addEventListener('click', function () {
                    runAction(action, root, status, button);
                });
                actions.appendChild(button);
            });
            if (actions.childElementCount > 0) li.appendChild(actions);
        }

        return li;
    }

    function renderGroupedResults(container, results, itemClass, groupPrefix, root, status) {
        container.replaceChildren();
        var groups = [];
        results.forEach(function (item) {
            var group = groups.find(function (entry) { return entry.type === item.type; });
            if (!group) {
                group = { type: item.type, label: item.label, items: [] };
                groups.push(group);
            }
            group.items.push(item);
        });

        groups.forEach(function (group) {
            var section = document.createElement('li');
            section.className = groupPrefix + '__group';
            var heading = document.createElement('h3');
            heading.className = groupPrefix + '__group-title';
            heading.textContent = group.label;
            var list = document.createElement('ul');
            list.className = groupPrefix + '__group-list';
            group.items.forEach(function (item) {
                list.appendChild(renderResult(item, itemClass, root, status));
            });
            section.append(heading, list);
            container.appendChild(section);
        });
    }

    function rememberToken(root, data) {
        if (typeof data.csrf_token === 'string' && data.csrf_token !== '') {
            root.setAttribute('data-csrf-token', data.csrf_token);
        }
    }

    function getSelectedTypes(container) {
        return Array.from(container.querySelectorAll('[data-search-type]:checked'))
            .map(function (input) { return input.value; });
    }

    function renderTypes(container, types, selected) {
        container.replaceChildren();
        types.forEach(function (type) {
            var label = document.createElement('label');
            label.className = 'global-search__type';
            var input = document.createElement('input');
            input.type = 'checkbox';
            input.value = type.id;
            input.checked = !selected || selected.length === 0 || selected.indexOf(type.id) !== -1;
            input.setAttribute('data-search-type', '');
            var text = document.createElement('span');
            text.textContent = type.label;
            label.append(input, text);
            container.appendChild(label);
        });
        if (types.length === 0) {
            var none = document.createElement('span');
            none.className = 'global-search__loading';
            none.textContent = 'Aucun contenu de recherche disponible.';
            container.appendChild(none);
        }
    }

    function requestUrl(endpoint, params) {
        var url = new URL(endpoint, window.location.origin);
        Object.keys(params).forEach(function (key) {
            url.searchParams.set(key, params[key]);
        });
        return url.toString();
    }

    function readResponse(response) {
        return response.json().then(function (data) {
            if (!response.ok || !data.success) {
                var errors = data.errors;
                var detail = typeof errors === 'string'
                    ? errors
                    : (errors && typeof errors === 'object' ? Object.values(errors).flat().join(' ') : '');
                throw new Error(data.message || detail || 'La recherche est temporairement indisponible.');
            }
            return data;
        });
    }

    function postAction(root, endpoint, payload) {
        var csrfToken = root.getAttribute('data-csrf-token');
        if (!csrfToken) return Promise.reject(new Error('Actualisez la page avant de continuer.'));

        return fetch(endpoint, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify(payload)
        }).then(readResponse);
    }

    function internalUrl(path) {
        var url = new URL(path, window.location.origin);
        if (url.origin !== window.location.origin) {
            throw new Error('Ce lien de recherche n’est pas autorisé.');
        }
        return url.toString();
    }

    function formatDetailDate(value) {
        if (!value) return '—';
        var date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return String(value);
        return new Intl.DateTimeFormat('fr-FR', {
            dateStyle: 'medium',
            timeStyle: 'short'
        }).format(date);
    }

    function showDetails(root, details) {
        var dialog = root.querySelector('[data-search-detail-dialog]')
            || document.querySelector('[data-search-detail-dialog]');
        if (!dialog || !details || (details.type !== 'user' && details.type !== 'group')) return false;

        var setText = function (key, value) {
            var target = dialog.querySelector('[data-search-detail="' + key + '"]');
            if (target) target.textContent = value == null || value === '' ? '—' : String(value);
        };
        var userPanel = dialog.querySelector('[data-search-detail-user]');
        var groupPanel = dialog.querySelector('[data-search-detail-group]');
        var heading = dialog.querySelector('[data-search-detail="heading"]');
        heading.textContent = details.name || '';
        userPanel.hidden = details.type !== 'user';
        groupPanel.hidden = details.type !== 'group';

        if (details.type === 'user') {
            var image = dialog.querySelector('[data-search-detail="profile_image"]');
            var initial = dialog.querySelector('[data-search-detail-initial]');
            if (details.profile_image) {
                image.src = internalUrl(details.profile_image);
                image.hidden = false;
                initial.hidden = true;
            } else {
                image.removeAttribute('src');
                image.hidden = true;
                initial.hidden = false;
                initial.textContent = String(details.name || '?').charAt(0).toUpperCase();
            }

            setText('name', details.name);
            setText('username', '@' + (details.username || ''));
            setText('id', details.id);
            setText('status', details.is_active ? 'Actif' : 'Inactif');
            setText('role', details.role);
            setText('two_factor', details.uses_tfa ? '2FA activée' : '2FA désactivée');
            setText('email', details.email);
            setText('email_verified', details.email_verified_at
                ? 'Le ' + formatDetailDate(details.email_verified_at)
                : 'Non vérifié');
            setText('tfa_method', details.tfa_method);
            setText('created_at', formatDetailDate(details.created_at));
            setText('last_activity', formatDetailDate(details.last_activity));
            dialog.querySelector('[data-search-detail-two-factor]').hidden = !details.uses_tfa;
        } else {
            setText('description', details.description || 'Aucune description.');
            setText('slug', details.slug);
            setText('visibility', details.is_private ? 'Privé' : 'Public');
            setText('membership', details.is_joinable ? 'Sur demande' : 'Adhésion directe');
        }

        dialog.showModal();
        return true;
    }

    function runAction(action, root, status, button) {
        try {
            if (action.action === 'view') {
                if (action.details && showDetails(root, action.details)) return;
                window.location.assign(internalUrl(action.url));
                return;
            }

            if (action.action === 'message') {
                var chatPanel = window.Alpine && window.Alpine.store('chatPanel');
                if (chatPanel && typeof chatPanel.openUser === 'function') {
                    chatPanel.openUser(action.user);
                    return;
                }

                postAction(root, '/dashboard/conversations/open', {
                    user_id: Number(action.user && action.user.id)
                }).then(function (data) {
                    var conversationId = Number(data.data && data.data.id);
                    if (!conversationId) throw new Error('La conversation n’a pas pu être ouverte.');
                    window.location.assign('/dashboard/conversations?open=' + conversationId);
                }).catch(function (error) {
                    status.textContent = error.message;
                });
                return;
            }

            if (action.action === 'join') {
                var endpoint = action.mode === 'request'
                    ? '/dashboard/groups/requests/create'
                    : '/dashboard/account/groups/join';
                postAction(root, endpoint, { group_id: Number(action.group_id) })
                    .then(function (data) {
                        button.disabled = true;
                        button.textContent = action.mode === 'request' ? 'Demande envoyée' : 'Rejoint';
                        status.textContent = data.message || button.textContent;
                    })
                    .catch(function (error) {
                        status.textContent = error.message;
                    });
            }
        } catch (error) {
            status.textContent = error.message;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-global-search]').forEach(function (root) {
            var dialog = root.querySelector('[data-search-dialog]');
            var openButton = root.querySelector('[data-search-open]');
            var closeButton = root.querySelector('[data-search-close]');
            var form = root.querySelector('[data-search-form]');
            var query = root.querySelector('[data-search-query]');
            var types = root.querySelector('[data-search-types]');
            var status = root.querySelector('[data-search-status]');
            var list = root.querySelector('[data-search-results]');
            var allLink = root.querySelector('[data-search-all]');
            var api = root.getAttribute('data-api');
            var resultsUrl = root.getAttribute('data-results-url');
            var busy = false;
            var detailDialog = root.querySelector('[data-search-detail-dialog]');

            function loadOptions() {
                status.textContent = 'Chargement des contenus disponibles…';
                fetch(requestUrl(api, { q: '' }), { headers: { Accept: 'application/json' } })
                    .then(readResponse)
                    .then(function (data) {
                        rememberToken(root, data);
                        renderTypes(types, data.types || []);
                        status.textContent = (data.types || []).length
                            ? 'Choisissez un ou plusieurs types de contenus, puis lancez la recherche.'
                            : 'Aucun contenu n’est actuellement disponible pour la recherche.';
                    })
                    .catch(function (error) {
                        types.replaceChildren();
                        status.textContent = error.message;
                    });
            }

            function submitSearch(event) {
                if (event) event.preventDefault();
                if (busy) return;
                var term = query.value.trim();
                var selected = getSelectedTypes(types);
                if (term.length < 2) {
                    status.textContent = 'Saisissez au moins deux caractères.';
                    query.focus();
                    return;
                }
                if (selected.length === 0) {
                    status.textContent = 'Sélectionnez au moins un type de contenu.';
                    return;
                }

                busy = true;
                status.textContent = 'Recherche en cours…';
                list.replaceChildren();
                allLink.hidden = true;
                fetch(requestUrl(api, {
                    q: term,
                    types: selected.join(','),
                    page: '1',
                    per_page: '5'
                }), { headers: { Accept: 'application/json' } })
                    .then(readResponse)
                    .then(function (data) {
                        rememberToken(root, data);
                        status.textContent = data.total + (data.total > 1 ? ' résultats trouvés.' : ' résultat trouvé.');
                        renderGroupedResults(
                            list,
                            data.results || [],
                            'global-search__result',
                            'global-search__result',
                            root,
                            status
                        );
                        if (!data.results || data.results.length === 0) {
                            var empty = document.createElement('li');
                            empty.className = 'global-search__empty';
                            empty.textContent = 'Aucun résultat pour cette recherche.';
                            list.appendChild(empty);
                        } else {
                            var target = new URL(resultsUrl, window.location.origin);
                            target.searchParams.set('q', term);
                            target.searchParams.set('types', selected.join(','));
                            allLink.href = target.pathname + target.search;
                            allLink.hidden = false;
                        }
                    })
                    .catch(function (error) {
                        status.textContent = error.message;
                    })
                    .finally(function () { busy = false; });
            }

            function openSearch() {
                dialog.showModal();
                query.focus();
                loadOptions();
            }
            if (openButton) openButton.addEventListener('click', openSearch);
            document.querySelectorAll('[data-search-open-proxy]').forEach(function (proxy) {
                if (proxy.getAttribute('data-search-scope') !== root.getAttribute('data-scope')) return;
                proxy.addEventListener('click', openSearch);
            });
            closeButton.addEventListener('click', function () { dialog.close(); });
            if (detailDialog) {
                detailDialog.querySelectorAll('[data-search-detail-close]').forEach(function (button) {
                    button.addEventListener('click', function () { detailDialog.close(); });
                });
                detailDialog.addEventListener('click', function (event) {
                    if (event.target === detailDialog) detailDialog.close();
                });
            }
            form.addEventListener('submit', submitSearch);
            query.addEventListener('input', function () {
                list.replaceChildren();
                allLink.hidden = true;
            });
            document.addEventListener('keydown', function (event) {
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault();
                    if (!dialog.open) openButton.click();
                    else query.focus();
                }
            });
        });

        document.querySelectorAll('[data-search-results-page]').forEach(function (pageRoot) {
            var form = pageRoot.querySelector('[data-results-form]');
            var query = pageRoot.querySelector('[data-results-query]');
            var types = pageRoot.querySelector('[data-results-types]');
            var status = pageRoot.querySelector('[data-results-status]');
            var list = pageRoot.querySelector('[data-results-list]');
            var previous = pageRoot.querySelector('[data-results-previous]');
            var next = pageRoot.querySelector('[data-results-next]');
            var pageLabel = pageRoot.querySelector('[data-results-page]');
            var pageNumber = 1;
            var pageCount = 1;
            var busy = false;
            var api = pageRoot.getAttribute('data-api');
            var initial = new URL(window.location.href);
            var initialTypes = (initial.searchParams.get('types') || '').split(',').filter(Boolean);

            function refreshResults(page) {
                var term = query.value.trim();
                var selected = getSelectedTypes(types);
                if (term.length < 2) {
                    status.textContent = 'Saisissez au moins deux caractères pour afficher les résultats.';
                    list.replaceChildren();
                    return;
                }
                if (selected.length === 0) {
                    status.textContent = 'Sélectionnez au moins un type de contenu.';
                    list.replaceChildren();
                    return;
                }
                if (busy) return;
                busy = true;
                status.textContent = 'Recherche en cours…';
                fetch(requestUrl(api, {
                    q: term,
                    types: selected.join(','),
                    page: String(page),
                    per_page: '20'
                }), { headers: { Accept: 'application/json' } })
                    .then(readResponse)
                    .then(function (data) {
                        rememberToken(pageRoot, data);
                        renderTypes(types, data.types || [], selected);
                        pageNumber = data.page;
                        pageCount = data.pages;
                        status.textContent = data.total + (data.total === 1 ? ' résultat au total.' : ' résultats au total.');
                        pageLabel.textContent = 'Page ' + pageNumber + ' sur ' + pageCount;
                        previous.disabled = pageNumber <= 1;
                        next.disabled = pageNumber >= pageCount;
                        renderGroupedResults(
                            list,
                            data.results || [],
                            'search-results-page__item',
                            'search-results-page',
                            pageRoot,
                            status
                        );
                        if (data.results.length === 0) {
                            var empty = document.createElement('li');
                            empty.className = 'global-search__empty';
                            empty.textContent = 'Aucun résultat pour cette recherche.';
                            list.appendChild(empty);
                        }
                        var url = new URL(window.location.href);
                        url.searchParams.set('q', term);
                        url.searchParams.set('types', selected.join(','));
                        url.searchParams.set('page', String(pageNumber));
                        window.history.replaceState({}, '', url.pathname + url.search);
                    })
                    .catch(function (error) { status.textContent = error.message; })
                    .finally(function () { busy = false; });
            }

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                refreshResults(1);
            });
            previous.addEventListener('click', function () { refreshResults(Math.max(1, pageNumber - 1)); });
            next.addEventListener('click', function () { refreshResults(Math.min(pageCount, pageNumber + 1)); });
            fetch(requestUrl(api, { q: '' }), { headers: { Accept: 'application/json' } })
                .then(readResponse)
                .then(function (data) {
                    rememberToken(pageRoot, data);
                    renderTypes(types, data.types || [], initialTypes);
                    query.value = initial.searchParams.get('q') || '';
                    pageNumber = Math.max(1, parseInt(initial.searchParams.get('page') || '1', 10) || 1);
                    if (query.value) refreshResults(pageNumber);
                    else status.textContent = 'Saisissez une recherche pour commencer.';
                })
                .catch(function (error) { status.textContent = error.message; });
        });
    });
})();
