document.addEventListener('alpine:init', () => {
    Alpine.store('pageManagement', {
        items: [],
        permissions: [],
        layouts: {},
        blocks: {},
        sectionTypes: {},
        sectionExamples: [],
        templates: [],
        library: [],
        menus: [],
        filters: {search: '', status: ''},
        form: {sections: []},
        managerOpen: false,
        managerTab: 'templates',
        templateForm: {},
        sectionForm: {},
        menuForm: {},
        menuItemForm: {},
        selectedMenuId: null,
        templatePreviewOpen: false,
        editorOpen: false,
        templatePickerOpen: false,
        templateTitle: '',
        selectedLibrary: '',
        libraryMode: 'reference',
        viewport: 'desktop',
        dirty: false,
        saving: false,
        _nextKey: 0,

        initialize(element = {}) {
            const data = element?.dataset
                ? JSON.parse(element.dataset.pageManagement || '{}')
                : element;
            this.items = data.items || [];
            this.permissions = data.permissions || [];
            this.layouts = data.layouts || {};
            this.blocks = data.blocks || {};
            this.sectionTypes = data.sections || {};
            this.sectionExamples = Array.isArray(data.section_examples) ? data.section_examples : [];
            this.templates = data.templates || [];
            this.library = data.library || [];
            this.form = this.emptyForm();
            window.addEventListener('beforeunload', (event) => {
                if (!this.dirty) return;
                event.preventDefault();
                event.returnValue = '';
            });
        },

        async archive(id) {
            if (!window.confirm('Archiver cette page ?')) return;
            try {
                const result = await api.post(`/dashboard/pages/${id}/archive`, {}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible d’archiver la page.');
                await this.loadItems();
                this.notify('Page archivée.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible d’archiver la page.', 'warning');
            }
        },

        async deletePage(id) {
            if (!window.confirm('Supprimer définitivement cette page ? Les pages utilisées dans un menu ne peuvent pas être supprimées.')) return;
            try {
                const result = await api.post(`/dashboard/pages/${id}/delete`, {}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de supprimer la page.');
                await this.loadItems();
                this.notify('Page supprimée.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de supprimer la page.', 'warning');
            }
        },

        can(permission) {
            return this.permissions.includes(permission) || window.currentUserIsSuper === true;
        },

        filteredItems() {
            const search = this.filters.search.trim().toLocaleLowerCase();
            return this.items.filter((page) => {
                const matchesSearch = !search || `${page.title} ${page.slug}`.toLocaleLowerCase().includes(search);
                return matchesSearch && (!this.filters.status || page.status === this.filters.status);
            });
        },

        async loadItems() {
            try {
                const result = await api.get('/dashboard/pages/data', {
                    params: {search: this.filters.search, status: this.filters.status},
                    toast: false
                });
                if (!result.success || !Array.isArray(result.data)) throw new Error(result.message || 'Impossible de charger les pages.');
                this.items = result.data;
            } catch (error) {
                this.notify(error.message || 'Impossible de charger les pages.', 'warning');
            }
        },

        async loadLibrary() {
            const [sections, templates, menus] = await Promise.all([
                api.get('/dashboard/pages/sections', {toast: false}),
                api.get('/dashboard/pages/templates', {toast: false}),
                api.get('/dashboard/pages/menus', {toast: false})
            ]);
            if (!sections.success || !templates.success || !menus.success) throw new Error('Impossible de charger les ressources CMS.');
            this.library = sections.data || [];
            this.templates = templates.data || [];
            this.menus = menus.data || [];
        },

        async openManager(tab = 'templates') {
            if (!['pages.manage_templates', 'pages.manage_sections', 'pages.manage_navigation'].some((permission) => this.can(permission))) return;
            try {
                await Promise.all([this.loadLibrary(), this.loadItems()]);
                if (tab === 'templates' && !this.can('pages.manage_templates')) {
                    tab = this.can('pages.manage_sections') ? 'sections' : 'menus';
                }
                this.managerTab = tab;
                this.managerOpen = true;
                this.templateForm = this.emptyTemplateForm();
                this.sectionForm = this.emptySectionForm();
                this.menuForm = this.emptyMenuForm();
                this.menuItemForm = this.emptyMenuItemForm();
                this.selectedMenuId = this.menus[0]?.id || null;
            } catch (error) {
                this.notify(error.message || 'Impossible de charger les outils CMS.', 'warning');
            }
        },

        emptyTemplateForm() {
            return {id: null, name: '', description: '', category: '', structureText: '[]', settingsText: '{}'};
        },

        editTemplate(template) {
            if (!this.can('pages.manage_templates') || template.origin !== 'custom') return;
            const structure = Array.isArray(template.structure) ? template.structure : (template.structure?.sections || []);
            this.templateForm = {
                id: Number(template.id),
                name: template.name || '',
                description: template.description || '',
                category: template.category || '',
                structureText: JSON.stringify(structure, null, 2),
                settingsText: JSON.stringify(template.settings || {}, null, 2)
            };
        },

        async saveTemplate() {
            if (!this.can('pages.manage_templates')) return;
            try {
                const structure = this.parseJsonList(this.templateForm.structureText, 'Structure du template');
                const settings = this.parseJson(this.templateForm.settingsText, 'Paramètres du template');
                const result = await api.post(`/dashboard/pages/templates/${this.templateForm.id}/update`, {
                    name: this.templateForm.name,
                    description: this.templateForm.description,
                    category: this.templateForm.category,
                    structure,
                    settings
                }, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible d’enregistrer le template.');
                await this.loadLibrary();
                this.templateForm = this.emptyTemplateForm();
                this.notify('Template enregistré.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible d’enregistrer le template.', 'warning');
            }
        },

        async createTemplateFromPage(id) {
            if (!this.can('pages.manage_templates')) return;
            const name = window.prompt('Nom du template à créer depuis cette page :');
            if (!name?.trim()) return;
            try {
                const result = await api.post(`/dashboard/pages/${id}/templates`, {name: name.trim()}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de créer le template.');
                await this.loadLibrary();
                this.managerTab = 'templates';
                this.managerOpen = true;
                this.notify('Template créé depuis la page.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de créer le template.', 'warning');
            }
        },

        async duplicateTemplate(template) {
            if (!this.can('pages.manage_templates') || template.origin !== 'custom') return;
            const name = window.prompt('Nom du template dupliqué :', `${template.name} (copie)`);
            if (!name?.trim()) return;
            try {
                const result = await api.post(`/dashboard/pages/templates/${template.id}/duplicate`, {name: name.trim()}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de dupliquer le template.');
                await this.loadLibrary();
                this.notify('Template dupliqué.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de dupliquer le template.', 'warning');
            }
        },

        async deleteTemplate(template) {
            if (!this.can('pages.manage_templates') || template.origin !== 'custom' || !window.confirm(`Supprimer le template « ${template.name} » ?`)) return;
            try {
                const result = await api.post(`/dashboard/pages/templates/${template.id}/delete`, {}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de supprimer le template.');
                await this.loadLibrary();
                if (Number(this.templateForm.id) === Number(template.id)) this.templateForm = this.emptyTemplateForm();
                this.notify('Template supprimé.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de supprimer le template.', 'warning');
            }
        },

        async previewTemplate(template) {
            if (!this.can('pages.preview')) return;
            try {
                const structure = Array.isArray(template.structure) ? template.structure : (template.structure?.sections || []);
                const sections = structure.map((section, index) => {
                    const base = {slot: section.slot || 'content', sort_order: Number(section.sort_order ?? index), enabled: this.asBoolean(section.enabled ?? true)};
                    if (section.library_section_id && section.usage_mode === 'reference') {
                        return {...base, library_section_id: Number(section.library_section_id)};
                    }
                    return {
                        ...base,
                        name: section.name,
                        type: section.type,
                        settings: section.settings || {},
                        blocks: (section.blocks || []).map((block, order) => ({
                            type: block.type,
                            configuration: block.configuration || {},
                            sort_order: Number(block.sort_order ?? order),
                            enabled: this.asBoolean(block.enabled ?? true)
                        }))
                    };
                });
                const result = await api.post('/dashboard/pages/preview', {
                    title: template.name,
                    layout: template.settings?.layout || 'default',
                    sections
                }, {toast: false});
                if (!result.success || !result.data) throw new Error(result.message || 'La prévisualisation a échoué.');
                this.templatePreviewOpen = true;
                await Alpine.nextTick();
                const frame = document.getElementById('page-management-template-preview');
                if (frame) frame.srcdoc = this.previewDocument(result.data);
            } catch (error) {
                this.notify(error.message || 'La prévisualisation a échoué.', 'warning');
            }
        },

        emptySectionForm() {
            return {id: null, name: '', type: Object.keys(this.sectionTypes)[0] || 'content', settingsText: '{}', blocksText: '[]'};
        },

        editLibrarySection(section) {
            if (!this.can('pages.manage_sections')) return;
            this.sectionForm = {
                id: Number(section.id),
                name: section.name || '',
                type: section.type || 'content',
                settingsText: JSON.stringify(section.settings || {}, null, 2),
                blocksText: JSON.stringify(section.blocks || [], null, 2)
            };
        },

        async saveLibrarySection() {
            if (!this.can('pages.manage_sections')) return;
            try {
                const settings = this.parseJson(this.sectionForm.settingsText, 'Paramètres de section');
                const blocks = this.parseJsonList(this.sectionForm.blocksText, 'Blocs de section');
                const payload = {
                    name: this.sectionForm.name,
                    type: this.sectionForm.type,
                    settings,
                    blocks: blocks.map((block, order) => ({
                        type: block.type,
                        configuration: block.configuration || {},
                        sort_order: Number(block.sort_order ?? order),
                        enabled: this.asBoolean(block.enabled ?? true)
                    }))
                };
                const url = this.sectionForm.id
                    ? `/dashboard/pages/sections/${this.sectionForm.id}/update`
                    : '/dashboard/pages/sections';
                const result = await api.post(url, payload, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible d’enregistrer la section.');
                await this.loadLibrary();
                this.sectionForm = this.emptySectionForm();
                this.notify('Section de bibliothèque enregistrée.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible d’enregistrer la section.', 'warning');
            }
        },

        async saveSectionToLibrary(index) {
            if (!this.can('pages.manage_sections')) return;
            const section = this.form.sections[index];
            if (!section || section.library_section_id) return;
            try {
                const result = await api.post('/dashboard/pages/sections', {
                    name: section.name,
                    type: section.type,
                    settings: section.settings || {},
                    blocks: section.blocks.map((block, order) => ({
                        type: block.type,
                        configuration: this.parseJson(block.configurationText, 'Configuration du bloc'),
                        sort_order: order,
                        enabled: Boolean(block.enabled)
                    }))
                }, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible d’enregistrer la section.');
                await this.loadLibrary();
                this.notify('Section ajoutée à la bibliothèque.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible d’enregistrer la section.', 'warning');
            }
        },

        async deleteLibrarySection(section) {
            if (!this.can('pages.manage_sections') || !window.confirm(`Supprimer « ${section.name} » de la bibliothèque ?`)) return;
            try {
                const result = await api.post(`/dashboard/pages/sections/${section.id}/delete`, {}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de supprimer la section.');
                await this.loadLibrary();
                if (Number(this.sectionForm.id) === Number(section.id)) this.sectionForm = this.emptySectionForm();
                this.notify('Section supprimée de la bibliothèque.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de supprimer la section.', 'warning');
            }
        },

        emptyMenuForm() {
            return {id: null, name: '', slug: '', location: ''};
        },

        emptyMenuItemForm() {
            return {id: null, label: '', type: 'custom', page_id: '', destination: '', parent_id: '', icon: '', target: '_self', sort_order: 0, active: true};
        },

        selectMenu(id) {
            this.selectedMenuId = id ? Number(id) : null;
            const menu = this.menus.find((item) => Number(item.id) === Number(id));
            this.menuForm = menu ? {id: Number(menu.id), name: menu.name, slug: menu.slug, location: menu.location || ''} : this.emptyMenuForm();
            this.menuItemForm = this.emptyMenuItemForm();
        },

        editMenuItem(item) {
            this.menuItemForm = {
                id: Number(item.id),
                label: item.label || '',
                type: item.type || 'custom',
                page_id: item.page_id || '',
                destination: item.destination || '',
                parent_id: item.parent_id || '',
                icon: item.icon || '',
                target: item.target || '_self',
                sort_order: Number(item.sort_order || 0),
                active: this.asBoolean(item.active)
            };
        },

        async saveMenu() {
            if (!this.can('pages.manage_navigation')) return;
            try {
                const url = this.menuForm.id
                    ? `/dashboard/pages/menus/${this.menuForm.id}/update`
                    : '/dashboard/pages/menus';
                const result = await api.post(url, this.menuForm, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible d’enregistrer le menu.');
                await this.loadLibrary();
                this.selectMenu(result.data.menu.id);
                this.notify('Menu enregistré.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible d’enregistrer le menu.', 'warning');
            }
        },

        async deleteMenu() {
            if (!this.can('pages.manage_navigation') || !this.menuForm.id || !window.confirm(`Supprimer le menu « ${this.menuForm.name} » et tous ses éléments ?`)) return;
            try {
                const result = await api.post(`/dashboard/pages/menus/${this.menuForm.id}/delete`, {}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de supprimer le menu.');
                await this.loadLibrary();
                this.selectMenu(this.menus[0]?.id || null);
                this.notify('Menu supprimé.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de supprimer le menu.', 'warning');
            }
        },

        async saveMenuItem() {
            if (!this.can('pages.manage_navigation') || !this.selectedMenuId) return;
            try {
                const item = {...this.menuItemForm, parent_id: this.menuItemForm.parent_id || null};
                if (item.type === 'page') item.page_id = Number(item.page_id);
                const url = item.id
                    ? `/dashboard/pages/menus/${this.selectedMenuId}/items/${item.id}/update`
                    : `/dashboard/pages/menus/${this.selectedMenuId}/items`;
                const result = await api.post(url, item, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible d’enregistrer l’élément.');
                await this.loadLibrary();
                this.selectMenu(this.selectedMenuId);
                this.notify('Élément de menu enregistré.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible d’enregistrer l’élément.', 'warning');
            }
        },

        async deleteMenuItem(item) {
            if (!this.can('pages.manage_navigation') || !window.confirm(`Supprimer « ${item.label} » ?`)) return;
            try {
                const result = await api.post(`/dashboard/pages/menus/${this.selectedMenuId}/items/${item.id}/delete`, {}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de supprimer l’élément.');
                await this.loadLibrary();
                this.selectMenu(this.selectedMenuId);
                this.notify('Élément supprimé.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de supprimer l’élément.', 'warning');
            }
        },

        parseJsonList(value, label) {
            try {
                const parsed = JSON.parse(value || '[]');
                if (!Array.isArray(parsed)) throw new Error();
                return parsed;
            } catch {
                throw new Error(`${label} : un tableau JSON est attendu.`);
            }
        },

        emptyForm() {
            return {
                id: null, revision: null, title: '', slug: '', description: '', status: 'draft',
                visibility: 'public', layout: 'default', featured_image: '', seo_title: '',
                seo_description: '', seo_image: '', canonical_url: '', no_index: false,
                is_homepage: false, show_header: true, show_footer: true,
                show_announcement_bar: true, add_to_navigation: false, navigation_label: '',
                navigation_order: 0, sections: []
            };
        },

        newPage() {
            if (!this.can('pages.create')) return;
            this.form = this.emptyForm();
            this.dirty = false;
            this.editorOpen = true;
            this.viewport = 'desktop';
            Alpine.nextTick(() => this.setPreview(''));
        },

        async editPage(id) {
            try {
                const result = await api.get(`/dashboard/pages/${id}/data`, {toast: false});
                if (!result.success || !result.data?.page) throw new Error(result.message || 'Impossible de charger cette page.');
                const page = result.data.page;
                const sections = (result.data.sections || []).map((section) => ({
                    ...section,
                    _key: this.key(),
                    library_section_id: section.usage_mode === 'reference' && section.in_library
                        ? Number(section.library_section_id)
                        : null,
                    settingsText: JSON.stringify(section.settings || {}, null, 2),
                    blocks: (section.blocks || []).map((block) => ({
                        ...block,
                        _key: this.key(),
                        configurationText: JSON.stringify(block.configuration || {}, null, 2)
                    }))
                }));
                this.form = {
                    ...this.emptyForm(),
                    ...page,
                    id: Number(page.id),
                    revision: Number(page.revision || 1),
                    no_index: this.asBoolean(page.no_index),
                    is_homepage: this.asBoolean(page.is_homepage),
                    show_header: this.asBoolean(page.show_header),
                    show_footer: this.asBoolean(page.show_footer),
                    show_announcement_bar: this.asBoolean(page.show_announcement_bar),
                    add_to_navigation: this.asBoolean(page.add_to_navigation),
                    sections
                };
                this.dirty = false;
                this.editorOpen = true;
                this.viewport = 'desktop';
                await Alpine.nextTick();
                await this.refreshPreview();
            } catch (error) {
                this.notify(error.message || 'Impossible de charger cette page.', 'warning');
            }
        },

        closeEditor() {
            if (this.dirty && !window.confirm('Les modifications non enregistrées seront perdues. Fermer ?')) return;
            this.editorOpen = false;
            this.dirty = false;
        },

        markDirty() {
            this.dirty = true;
        },

        key() {
            this._nextKey += 1;
            return `page-editor-${this._nextKey}`;
        },

        addSection() {
            const type = Object.keys(this.sectionTypes)[0] || 'content';
            this.form.sections.push({
                _key: this.key(), name: 'Nouvelle section', type, slot: 'content',
                enabled: true, settings: {}, settingsText: '{}', blocks: []
            });
            this.markDirty();
        },

        addExampleSection(example) {
            if (!example || !Array.isArray(example.blocks)) return;
            this.form.layout = 'shelf';
            this.form.sections.push({
                _key: this.key(),
                name: example.name || 'Section d’exemple',
                type: example.type || 'content',
                slot: 'content',
                enabled: true,
                settings: example.settings || {},
                settingsText: JSON.stringify(example.settings || {}, null, 2),
                blocks: example.blocks.map((block) => ({
                    type: block.type,
                    enabled: true,
                    configurationText: JSON.stringify(block.configuration || {}, null, 2),
                    _key: this.key()
                }))
            });
            this.markDirty();
        },

        async addLibrary() {
            const sectionId = Number(this.selectedLibrary);
            if (!sectionId) return;
            const section = this.library.find((entry) => Number(entry.id) === sectionId);
            if (!section) return;
            this.form.sections.push({
                _key: this.key(),
                library_section_id: sectionId,
                in_library: true,
                usage_mode: this.libraryMode,
                name: section.name,
                type: section.type,
                slot: 'content',
                enabled: true,
                settings: section.settings || {},
                settingsText: JSON.stringify(section.settings || {}, null, 2),
                blocks: (section.blocks || []).map((block) => ({
                    ...block,
                    _key: this.key(),
                    configurationText: JSON.stringify(block.configuration || {}, null, 2)
                }))
            });
            if (this.libraryMode === 'copy') {
                this.form.sections[this.form.sections.length - 1].library_section_id = null;
            }
            this.selectedLibrary = '';
            this.markDirty();
        },

        removeSection(index) {
            this.form.sections.splice(index, 1);
            this.markDirty();
        },

        moveSection(index, direction) {
            this.move(this.form.sections, index, direction);
        },

        addBlock(sectionIndex) {
            const type = Object.keys(this.blocks)[0] || 'rich_text';
            this.form.sections[sectionIndex].blocks.push({
                _key: this.key(), type, enabled: true, configurationText: this.defaultBlockConfig(type)
            });
            this.markDirty();
        },

        duplicateBlock(sectionIndex, blockIndex) {
            const source = this.form.sections[sectionIndex].blocks[blockIndex];
            this.form.sections[sectionIndex].blocks.splice(blockIndex + 1, 0, {
                ...source, _key: this.key()
            });
            this.markDirty();
        },

        removeBlock(sectionIndex, blockIndex) {
            this.form.sections[sectionIndex].blocks.splice(blockIndex, 1);
            this.markDirty();
        },

        moveBlock(sectionIndex, blockIndex, direction) {
            this.move(this.form.sections[sectionIndex].blocks, blockIndex, direction);
        },

        move(items, index, direction) {
            const target = index + direction;
            if (target < 0 || target >= items.length) return;
            [items[index], items[target]] = [items[target], items[index]];
            this.markDirty();
        },

        defaultBlockConfig(type) {
            const defaults = {
                rich_text: {html: '<p>Nouveau contenu</p>'},
                heading: {text: 'Nouveau titre', level: 2},
                image: {url: '/assets/images/placeholder.jpg', alt: '', caption: ''},
                gallery: {images: []},
                video: {url: 'https://youtu.be/', title: ''},
                button: {label: 'En savoir plus', url: '/'},
                call_to_action: {title: 'À découvrir', text: '', label: '', url: '/'},
                columns: {columns: [{text: 'Colonne 1'}, {text: 'Colonne 2'}]},
                separator: {},
                spacer: {height: 32},
                banner: {text: 'Votre message', image: ''},
                html: {html: '<p>Contenu HTML assaini</p>'},
                shelf_hero: {title: 'Bienvenue chez vous', description: 'Présentez votre activité en une phrase simple.'},
                shelf_panel: {title: 'À propos de votre projet', text: 'Ajoutez ici une information utile à vos visiteurs.'},
                shelf_row: {label: 'Cartes de présentation', items: [{title: 'Votre offre', text: 'Décrivez ce que vous proposez.'}]}
            };
            return JSON.stringify(defaults[type] || {}, null, 2);
        },

        buildInput() {
            const sections = this.form.sections.map((section, order) => {
                const base = {
                    slot: section.slot || 'content',
                    sort_order: order,
                    enabled: Boolean(section.enabled)
                };
                if (section.library_section_id && section.usage_mode === 'reference') {
                    return {...base, library_section_id: Number(section.library_section_id), usage_mode: 'reference'};
                }
                return {
                    ...base,
                    name: section.name,
                    type: section.type,
                    settings: section.settings || {},
                    blocks: section.blocks.map((block, blockOrder) => ({
                        type: block.type,
                        configuration: this.parseJson(block.configurationText, 'Configuration du bloc'),
                        sort_order: blockOrder,
                        enabled: Boolean(block.enabled)
                    }))
                };
            });
            return {
                id: this.form.id,
                revision: this.form.revision,
                title: this.form.title,
                slug: this.form.slug,
                description: this.form.description,
                status: this.form.status,
                visibility: this.form.visibility,
                layout: this.form.layout,
                featured_image: this.form.featured_image,
                seo_title: this.form.seo_title,
                seo_description: this.form.seo_description,
                seo_image: this.form.seo_image,
                canonical_url: this.form.canonical_url,
                no_index: Boolean(this.form.no_index),
                show_header: Boolean(this.form.show_header),
                show_footer: Boolean(this.form.show_footer),
                show_announcement_bar: Boolean(this.form.show_announcement_bar),
                add_to_navigation: Boolean(this.form.add_to_navigation),
                navigation_label: this.form.navigation_label,
                navigation_order: Number(this.form.navigation_order || 0),
                sections
            };
        },

        parseJson(value, label) {
            try {
                const parsed = JSON.parse(value || '{}');
                if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') throw new Error();
                return parsed;
            } catch {
                throw new Error(`${label} : JSON invalide.`);
            }
        },

        async save() {
            if (this.saving) return;
            this.saving = true;
            try {
                const payload = this.buildInput();
                const url = payload.id ? `/dashboard/pages/${payload.id}/update` : '/dashboard/pages/create';
                const result = await api.post(url, payload, {toast: false});
                if (!result.success || !result.data?.page) throw new Error(result.message || 'Impossible d’enregistrer la page.');
                this.form.id = Number(result.data.page.id);
                this.form.revision = Number(result.data.page.revision || 1);
                this.form.slug = result.data.page.slug;
                this.dirty = false;
                await this.loadItems();
                await this.refreshPreview();
                this.notify('Page enregistrée.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible d’enregistrer la page.', 'warning');
            } finally {
                this.saving = false;
            }
        },

        async refreshPreview() {
            try {
                const result = await api.post('/dashboard/pages/preview', this.buildInput(), {toast: false});
                if (!result.success || !result.data) throw new Error(result.message || 'La prévisualisation a échoué.');
                this.setPreview(this.previewDocument(result.data));
            } catch (error) {
                this.notify(error.message || 'La prévisualisation a échoué.', 'warning');
            }
        },

        previewDocument(data) {
            const title = this.escapeHtml(data.seo?.title || data.page?.title || 'Aperçu');
            const shelfLayout = data.layout === 'shelf';
            const body = (data.sections || []).map((section) =>
                `<section class="${shelfLayout ? 'min-w-0 space-y-10' : ''}" data-slot="${this.escapeHtml(section.slot || 'content')}">${(section.blocks || []).map((block) => block.html || '').join('')}</section>`
            ).join('');
            const shelfAssets = shelfLayout
                ? '<link rel="stylesheet" href="/assets/css/tailwind.css"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anybody:wdth,wght@50..150,100..900&display=swap">'
                : '';
            const page = shelfLayout
                ? `<main class="shelf min-h-screen overflow-x-hidden bg-[#cfe8dc] text-[#10302c] antialiased dark:bg-[#0c1c1a] dark:text-[#cfe8dc]"><div class="mx-auto max-w-7xl space-y-16 px-4 pb-24 pt-6 sm:px-6 lg:px-8">${body}</div></main>`
                : `<main>${body}</main>`;
            return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title}</title>${shelfAssets}<style>
                *{box-sizing:border-box}body{margin:0;padding:${shelfLayout ? '0' : '28px'};color:${shelfLayout ? '#10302c' : '#172a32'};font:16px/1.65 ${shelfLayout ? "'Anybody',ui-sans-serif,system-ui,sans-serif" : 'Georgia,serif'}}main{${shelfLayout ? 'width:100%;margin:0' : 'max-width:900px;margin:auto'}}
                section{margin:0 auto ${shelfLayout ? '0' : '28px'}}.page-button{display:inline-block;padding:10px 18px;background:#16353a;color:#fff;text-decoration:none}
                .page-gallery,.page-columns{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px}
                img{max-width:100%;height:auto}.page-cta,.page-banner{padding:24px;background:#e6eee2;border-radius:10px}
                ${shelfLayout ? '.shelf{font-family:"Anybody",ui-sans-serif,system-ui,sans-serif}.page-shelf-sign{transform-origin:50% 0;transform:rotate(-1.2deg)}' : ''}
            </style></head><body>${page}</body></html>`;
        },

        setPreview(html) {
            const frame = document.getElementById('page-management-preview');
            if (frame) frame.srcdoc = html || '<!doctype html><html><body style="font:14px Georgia;padding:2rem;color:#587075">Votre aperçu apparaîtra ici.</body></html>';
        },

        async createFromTemplate(template) {
            const title = this.templateTitle.trim();
            if (!title) {
                this.notify('Saisissez le titre de la nouvelle page.', 'warning');
                return;
            }
            try {
                const result = await api.post('/dashboard/pages/create-from-template', {template, title}, {toast: false});
                if (!result.success || !result.data?.page) throw new Error(result.message || 'Impossible de créer la page.');
                this.templatePickerOpen = false;
                this.templateTitle = '';
                await this.loadItems();
                await this.editPage(result.data.page.id);
                this.notify('Page créée depuis le template.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de créer la page.', 'warning');
            }
        },

        openTemplatePicker() {
            this.templateTitle = '';
            this.templatePickerOpen = true;
        },

        async designateHomepage(id) {
            try {
                const result = await api.post(`/dashboard/pages/${id}/homepage`, {}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de définir la page d’accueil.');
                await this.loadItems();
                this.notify('Page d’accueil mise à jour.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de définir la page d’accueil.', 'warning');
            }
        },

        async duplicate(id) {
            try {
                const result = await api.post(`/dashboard/pages/${id}/duplicate`, {}, {toast: false});
                if (!result.success) throw new Error(result.message || 'Impossible de dupliquer la page.');
                await this.loadItems();
                this.notify('Page dupliquée en brouillon.', 'success');
            } catch (error) {
                this.notify(error.message || 'Impossible de dupliquer la page.', 'warning');
            }
        },

        statusClass(status) {
            return {
                draft: 'bg-[#e6e5df] text-[#657273]',
                published: 'bg-[#dbe7d5] text-[#355e46]',
                archived: 'bg-[#f5e1d5] text-[#9b563c]'
            }[status] || 'bg-[#e6e5df] text-[#657273]';
        },

        statusLabel(status) {
            return {draft: 'Brouillon', published: 'Publiée', archived: 'Archivée'}[status] || status;
        },

        layoutName(id) {
            return this.layouts[id]?.name || id;
        },

        formatDate(value) {
            if (!value) return '—';
            const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
            return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('fr-FR', {dateStyle: 'medium'}).format(date);
        },

        asBoolean(value) {
            return value === true || value === 1 || value === '1' || value === 'true';
        },

        escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, (char) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            })[char]);
        },

        notify(message, type = 'warning') {
            if (window.Toaster?.[type]) window.Toaster[type](message);
            else if (window.Toaster?.show) window.Toaster.show(message, type);
        }
    });
});
