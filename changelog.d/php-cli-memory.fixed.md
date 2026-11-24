- Stop `bin/console`, the Symfony test suite and the entry-point classifier from running
  out of memory under PHP's 128 MB CLI default: the gettext bridge and the classifier
  no longer hold every parsed catalog or syntax tree, and each tool raises a low limit.
