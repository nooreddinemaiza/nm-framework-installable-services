document.addEventListener('alpine:init', () => {
    Alpine.store('articles', {
        items: [],
        categories: [],
        statistics: {},
        popular: [],
        filters: {},
        total: 0,
        page: 1,
        pages: 1,
        perPage: 10,
        loading: false,
        canCreate: false,
        canUpdate: false,
        canPublish: false,
        canDelete: false,
        canManageCategories: false,
        canViewStats: false,

        initialize(data = {}) {
            this.items = data.items || [];
            this.categories = data.categories || [];
            this.statistics = data.statistics || {};
            this.popular = data.popular || [];
            this.filters = {
                search: '',
                status: 'all',
                category_id: '0',
                sort: 'updated',
                ...data.filters
            };
            this.total = Number(data.total || 0);
            this.page = Number(data.page || 1);
            this.pages = Number(data.pages || 1);
            this.perPage = Number(data.per_page || 10);
            this.canCreate = Boolean(data.canCreate);
            this.canUpdate = Boolean(data.canUpdate);
            this.canPublish = Boolean(data.canPublish);
            this.canDelete = Boolean(data.canDelete);
            this.canManageCategories = Boolean(data.canManageCategories);
            this.canViewStats = Boolean(data.canViewStats);
        },

        async query(ctx = {}) {
            this.loading = true;
            try {
                const result = await api.get('/dashboard/articles/data', {
                    params: {
                        ...this.filters,
                        page: ctx.page || 1,
                        per_page: ctx.pageSize || this.perPage
                    },
                    toast: false,
                    signal: ctx.signal
                });
                const data = result.data;
                if (!result.success || !data || !Array.isArray(data.items)) {
                    throw new Error(result.message || 'Impossible de charger les articles.');
                }
                this.items = data.items;
                this.categories = data.categories || [];
                this.statistics = data.statistics || {};
                this.popular = data.popular || [];
                this.total = Number(data.total || 0);
                this.page = Number(data.page || 1);
                this.pages = Number(data.pages || 1);
                this.perPage = Number(data.per_page || this.perPage);
            } finally {
                this.loading = false;
            }
        },

        async refresh(page = this.page) {
            try {
                const table = Alpine.store('tables')?.get('articles-table');
                if (table) {
                    table.page = page;
                    await table.reload();
                } else {
                    await this.query({ page, pageSize: this.perPage });
                }
            } catch (error) {
                Toaster.warning(`Action effectuée, mais la liste des articles n’a pas pu être actualisée : ${error.message}`);
            }
        },

        async applyFilters() {
            try {
                await this.refresh(1);
            } catch (error) {
                Toaster.warning(error.message || 'Impossible d’appliquer les filtres.');
            }
        },

        async resetFilters() {
            this.filters = { search: '', status: 'all', category_id: '0', sort: 'updated' };
            await this.applyFilters();
        },

        async openNew() {
            await this.openForm(null);
        },

        async openArticle(id) {
            try {
                const result = await api.get(`/dashboard/articles/${id}/data`, { toast: false });
                if (!result.success || !result.data?.article) {
                    throw new Error(result.message || 'Impossible de charger cet article.');
                }
                await this.openForm(result.data.article);
            } catch (error) {
                Toaster.warning(error.message || 'Impossible de charger cet article.');
            }
        },

        async openForm(article) {
            const categoryFields = this.categories.map((category) => ({
                name: `category_${category.id}`,
                label: category.name,
                type: 'checkbox'
            }));
            const values = {
                title: article?.title || '',
                slug: article?.slug || '',
                excerpt: article?.excerpt || '',
                content: article?.content || '',
                featured_image: article?.featured_image || '',
                status: 'draft',
                ...Object.fromEntries(this.categories.map((category) => [
                    `category_${category.id}`,
                    (article?.category_ids || []).includes(Number(category.id))
                ]))
            };
            const fields = [
                { name: 'title', label: 'Titre', type: 'text', required: true, maxLength: 190, fullWidth: true },
                { name: 'slug', label: 'Slug', type: 'slug', from: 'title', regenerateOnSourceChange: true, maxLength: 220, fullWidth: true, hint: 'Laissez vide pour le générer automatiquement.' },
                { name: 'excerpt', label: 'Résumé', type: 'textarea', rows: 3, maxLength: 1000, fullWidth: true },
                {
                    name: 'content', label: 'Contenu', type: 'richtext', rows: 12, maxLength: 1000000,
                    required: true, maxImageSize: 500000,
                    minHeight: 300, fullWidth: true
                },
                { name: 'featured_image', label: 'Image principale (URL ou chemin local)', type: 'text', maxLength: 255, fullWidth: true },
                ...(categoryFields.length ? [{ name: 'categories', type: 'section', label: 'Catégories' }, ...categoryFields] : []),
                ...(!article && this.canPublish ? [{
                    name: 'status',
                    label: 'État initial',
                    type: 'select',
                    required: true,
                    options: [
                        { value: 'draft', label: 'Brouillon' },
                        { value: 'published', label: 'Publié' }
                    ],
                    fullWidth: true
                }] : [])
            ];
            await Form.open({
                variant: article ? 'edit' : 'new',
                title: article ? 'Modifier l’article' : 'Ajouter un article',
                confirmText: 'Enregistrer',
                cancelText: 'Annuler',
                size: '4xl',
                values,
                fields,
                onSubmit: async (formValues, { close, setError, setErrors }) => {
                    const categoryIds = this.categories
                        .filter((category) => formValues[`category_${category.id}`])
                        .map((category) => Number(category.id));
                    const payload = {
                        title: formValues.title,
                        slug: formValues.slug,
                        excerpt: formValues.excerpt,
                        content: formValues.content,
                        featured_image: formValues.featured_image,
                        category_ids: categoryIds
                    };
                    if (!article && this.canPublish) payload.status = formValues.status || 'draft';
                    const url = article
                        ? `/dashboard/articles/${article.id}/update`
                        : '/dashboard/articles/create';
                    try {
                        const result = await api.post(url, payload, { toast: false });
                        if (!result.success) throw new Error(result.message || 'Impossible d’enregistrer l’article.');
                        Toaster.success(result.message || 'Article enregistré.');
                        close();
                        await this.refresh(article ? this.page : 1);
                    } catch (error) {
                        if (error.errors) {
                            const errors = { ...error.errors };
                            const formError = errors.form;
                            delete errors.form;
                            setErrors(errors);
                            if (formError) setError(Array.isArray(formError) ? formError[0] : formError);
                            return;
                        }
                        throw error;
                    }
                }
            });
        },

        async openCategory() {
            if (!this.canManageCategories) return;
            await Form.open({
                variant: 'new',
                title: 'Ajouter une catégorie',
                confirmText: 'Ajouter',
                cancelText: 'Annuler',
                values: { name: '', description: '' },
                fields: [
                    { name: 'name', label: 'Nom', type: 'text', required: true, maxLength: 190 },
                    { name: 'description', label: 'Description', type: 'textarea', rows: 3, fullWidth: true }
                ],
                onSubmit: async (values, { close, setError, setErrors }) => {
                    try {
                        const result = await api.post('/dashboard/articles/categories', values, { toast: false });
                        if (!result.success) throw new Error(result.message || 'Impossible de créer la catégorie.');
                        this.categories = result.data?.categories || this.categories;
                        Toaster.success(result.message || 'Catégorie créée.');
                        close();
                        await this.refresh(1);
                    } catch (error) {
                        if (error.errors) {
                            const errors = { ...error.errors };
                            const formError = errors.form;
                            delete errors.form;
                            setErrors(errors);
                            if (formError) setError(Array.isArray(formError) ? formError[0] : formError);
                            return;
                        }
                        throw error;
                    }
                }
            });
        },

        async confirmStatus(article, status) {
            const publish = status === 'published';
            const restoringPublished = publish && article.status === 'archived';
            const restoringDraft = !publish && article.status === 'archived';
            const actionLabel = restoringPublished
                ? 'Restaurer et publier'
                : (restoringDraft ? 'Restaurer en brouillon' : (publish ? 'Publier' : 'Dépublier'));
            await Form.open({
                variant: publish ? 'add' : 'edit',
                title: restoringPublished || restoringDraft
                    ? 'Restaurer l’article'
                    : (publish ? 'Publier l’article' : 'Dépublier l’article'),
                message: `${actionLabel} « ${article.title} » ?`,
                confirmText: actionLabel,
                cancelText: 'Annuler',
                onSubmit: async (_, { close }) => {
                    await this.mutate(
                        `/dashboard/articles/${article.id}/${publish ? 'publish' : 'unpublish'}`,
                        restoringDraft ? 'Article restauré en brouillon.' : (publish ? 'Article publié.' : 'Article dépublié.'),
                        close
                    );
                }
            });
        },

        async confirmArchive(article) {
            await this.confirmAction(
                'Archiver l’article',
                `Archiver « ${article.title} » ?`,
                `/dashboard/articles/${article.id}/archive`,
                'Article archivé.'
            );
        },

        async confirmDelete(article) {
            await this.confirmAction(
                'Supprimer l’article',
                `Supprimer définitivement « ${article.title} » ?`,
                `/dashboard/articles/${article.id}/delete`,
                'Article supprimé.'
            );
        },

        async confirmDeleteCategory(category) {
            await this.confirmAction(
                'Supprimer la catégorie',
                `Supprimer « ${category.name} » ? Les articles associés seront conservés.`,
                `/dashboard/articles/categories/${category.id}/delete`,
                'Catégorie supprimée.',
                true
            );
        },

        async confirmAction(title, message, url, successMessage, refreshCategories = false) {
            await Form.open({
                variant: 'delete',
                title,
                message,
                confirmText: 'Confirmer',
                cancelText: 'Annuler',
                onSubmit: async (_, { close }) => {
                    await this.mutate(url, successMessage, close, refreshCategories);
                }
            });
        },

        async mutate(url, fallbackMessage, close, refreshCategories = false) {
            const result = await api.post(url, {}, { toast: false });
            if (!result.success) throw new Error(result.message || 'Impossible d’effectuer cette action.');
            if (refreshCategories) this.categories = result.data?.categories || this.categories;
            Toaster.success(result.message || fallbackMessage);
            close();
            await this.refresh(1);
        }
    });
});
