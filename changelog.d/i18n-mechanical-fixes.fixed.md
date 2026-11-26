- Align `bn-BD`, `fa-IR`, and `zu-ZA` CLDR `n=0` plural rules, strip baked-in
  HTML entities, and repair markup, bracket, whitespace, and punctuation
  defects across shipped gettext and Symfony YAML translation catalogs.
- Wire HTML `dir="rtl"` for RTL locales across Twig and legacy page headers
  and add missing `lang` attribute on the logout page.
- Escape localized strings emitted into JavaScript literals with `json_encode`
  to prevent syntax breaks from translated quotes.
