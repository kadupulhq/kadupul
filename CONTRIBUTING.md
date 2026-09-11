# Contributing

Nothing here works yet. The fork has not been taken, so there is no code to
change and no build to run. If you want to help, the useful thing right now is
to open an issue about what the fork should do differently from Cacti.

What follows is how the project will work once there is code.

## Before you write anything

Open an issue first for anything beyond a typo. A patch that arrives without a
discussion tends to solve a problem nobody had agreed was the problem.

Say whether the bug also exists in stock Cacti. That tells us if we inherited it
or introduced it, which changes where the fix goes. Kadupul does not send patches
upstream, so a fix for inherited behaviour lands here.

## Commits

Sign off every commit. This project uses the
[Developer Certificate of Origin](https://developercertificate.org/), and the
sign-off is how you assert you have the right to submit the work.

```
git commit -s
```

Keep one logical change per commit. Write the subject in the imperative, under
about seventy characters, and explain why in the body when the why is not
obvious from the diff.

## Pull requests

One purpose per pull request. A bug fix fixes the bug. Refactoring that would be
welcome on its own merits still goes in its own pull request, because bundling
it means a maintainer who wants the fix has to accept the refactor too.

Rebase onto the base branch. Do not merge the base branch into your topic
branch.

Include the output of whatever you ran to verify the change. A passing test is
evidence only when its assertions exercise the behaviour you changed. For a bug
fix, show the failure before and the pass after.

## Style

The repository configuration is the authority, not this file. Match the code
around what you are changing, including its error handling and control flow. If
a file returns status codes, keep returning them rather than starting to throw.

PHP follows Cacti's conventions: tabs for indentation, opening brace on the same
line, single quotes unless interpolation is needed, `print` rather than `echo`.

## What gets rejected

Renaming symbols for consistency. Reformatting code the change does not touch.
Moving files for tidiness. New abstractions introduced without a caller that
needs them.
