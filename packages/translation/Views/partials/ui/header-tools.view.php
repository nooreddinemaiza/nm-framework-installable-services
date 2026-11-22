{{-- Thème + langue : seule implémentation, utilisée par app/auth/public --}}
<button type="button" class="min-h-11 min-w-11 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800"
  @click="$store.theme.toggle()" :aria-label="$store.i18n.t('header.theme')">
  <span x-show="!$store.theme.isDark"><x-svg name="moon" /></span>
  <span x-show="$store.theme.isDark" x-cloak><x-svg name="sun" /></span>
</button>
<form method="post" action="/locale">
  <input type="hidden" name="csrf_token" value="{{ $translation_csrf_token }}">
  <input type="hidden" name="return_to" value="{{ $locale_return_to }}">
  <label class="sr-only" for="ui-lang" x-text="$store.i18n.t('header.language')"></label>
  <select id="ui-lang" name="locale" class="rounded-lg border border-slate-300 bg-transparent px-2 py-1 text-sm dark:border-slate-700"
    onchange="this.form.submit()">
    @foreach($available_locales as $code => $label)
    <option value="{{ $code }}" @if($locale === $code) selected @endif>{{ $label }}</option>
    @endforeach
  </select>
</form>
