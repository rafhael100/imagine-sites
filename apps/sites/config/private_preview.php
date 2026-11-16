<?php
return [
 'enabled'=>env('SITES_PRIVATE_PREVIEW_ENABLED',false),
 // Deployment-owned mount. No user/browser chosen destination or SQL credential.
 'principals_file'=>'/run/secrets/preview_principals',
];
