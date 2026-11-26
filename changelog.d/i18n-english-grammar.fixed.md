- Correct grammar, spelling, punctuation and terminology in 220 English
  interface strings (203 gettext messages and 17 Symfony translation keys),
  such as "loose" for "lose", "take affect" for "take effect" and doubled
  words, and carry every existing translation over to the corrected text.
  Third-party plugins that reuse one of these core English strings lose its
  core translation until they use the corrected text.
