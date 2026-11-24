- Compile the project's own browser modules from TypeScript sources in
  `frontend/` into `include/js/app/` during `npm run build`. The permission
  denied page's Return handler is the first module built this way; its script
  URL moves from `include/js/permission-denied.js` to
  `include/js/app/permission-denied.js`.
