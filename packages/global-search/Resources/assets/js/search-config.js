document.addEventListener('DOMContentLoaded', function () {
    var configPanel = document.querySelector('[aria-labelledby="search-configuration-title"]');
    var openButton = document.querySelector('[data-search-config]');
    if (!configPanel || !openButton) return;

    var form = configPanel.querySelector('[data-search-config-form]');
    var search = configPanel.querySelector('[data-search-config-search]');
    var scopes = Array.from(configPanel.querySelectorAll('[data-search-config-scope]'));
    var initialValues = new Map();

    var scopeInputs = function (scope) {
        return Array.from(scope.querySelectorAll('[data-search-config-type]'));
    };

    var updateScope = function (scope) {
        var inputs = scopeInputs(scope);
        var selected = inputs.filter(function (input) { return input.checked; }).length;
        var all = scope.querySelector('[data-search-config-all]');
        var count = scope.querySelector('[data-search-config-count]');

        if (all) {
            all.checked = inputs.length > 0 && selected === inputs.length;
            all.indeterminate = selected > 0 && selected < inputs.length;
        }
        if (count) count.textContent = String(selected);

        scope.querySelectorAll('[data-search-config-group]').forEach(function (group) {
            var groupInputs = Array.from(group.querySelectorAll('[data-search-config-type]'));
            var groupSelected = groupInputs.filter(function (input) { return input.checked; }).length;
            var groupAll = group.querySelector('[data-search-config-group-all]');
            if (!groupAll) return;
            groupAll.checked = groupInputs.length > 0 && groupSelected === groupInputs.length;
            groupAll.indeterminate = groupSelected > 0 && groupSelected < groupInputs.length;
        });
    };

    var updateVisibility = function () {
        var term = search ? search.value.trim().toLocaleLowerCase() : '';
        scopes.forEach(function (scope) {
            scope.querySelectorAll('[data-search-config-group]').forEach(function (group) {
                var groupName = (group.getAttribute('data-search-config-group') || '').toLocaleLowerCase();
                var matches = 0;
                group.querySelectorAll('[data-search-config-option]').forEach(function (option) {
                    var visible = !term
                        || groupName.includes(term)
                        || option.textContent.toLocaleLowerCase().includes(term);
                    option.hidden = !visible;
                    if (visible) matches++;
                });
                group.hidden = matches === 0;
            });
        });
    };

    scopes.forEach(function (scope) {
        var scopeName = scope.getAttribute('data-search-config-scope');
        initialValues.set(scopeName, scopeInputs(scope)
            .filter(function (input) { return input.checked; })
            .map(function (input) { return input.value; }));

        scope.addEventListener('change', function (event) {
            if (event.target.matches('[data-search-config-all]')) {
                scopeInputs(scope).forEach(function (input) {
                    input.checked = event.target.checked;
                });
            } else if (event.target.matches('[data-search-config-group-all]')) {
                var group = event.target.closest('[data-search-config-group]');
                if (group) {
                    group.querySelectorAll('[data-search-config-type]').forEach(function (input) {
                        input.checked = event.target.checked;
                    });
                }
            }
            updateScope(scope);
        });

        var selectAll = configPanel.querySelector('[data-search-config-select-all="' + scopeName + '"]');
        var clear = configPanel.querySelector('[data-search-config-clear="' + scopeName + '"]');
        var reset = configPanel.querySelector('[data-search-config-reset="' + scopeName + '"]');
        if (selectAll) {
            selectAll.addEventListener('click', function () {
                scopeInputs(scope).forEach(function (input) { input.checked = true; });
                updateScope(scope);
            });
        }
        if (clear) {
            clear.addEventListener('click', function () {
                scopeInputs(scope).forEach(function (input) { input.checked = false; });
                updateScope(scope);
            });
        }
        if (reset) {
            reset.addEventListener('click', function () {
                var initial = initialValues.get(scopeName) || [];
                scopeInputs(scope).forEach(function (input) {
                    input.checked = initial.includes(input.value);
                });
                updateScope(scope);
            });
        }

        updateScope(scope);
    });

    if (search) search.addEventListener('input', updateVisibility);
    openButton.addEventListener('click', function () {
        window.setTimeout(function () {
            if (search) search.focus();
        }, 0);
    });

    form.addEventListener('submit', function () {
        scopes.forEach(function (scope) {
            var scopeName = scope.getAttribute('data-search-config-scope');
            var selected = scopeInputs(scope)
                .filter(function (input) { return input.checked; })
                .map(function (input) { return input.value; });
            var value = configPanel.querySelector('[data-search-config-value="' + scopeName + '"]');
            if (value) value.value = selected.join(',');
        });
    });
});
