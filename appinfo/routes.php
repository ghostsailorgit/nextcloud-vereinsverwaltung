<?php
return [
    'routes' => [
        // Dashboard page
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // Dashboard data endpoints
        // Clubs (Vereine) and their bank accounts
        ['name' => 'club#index', 'url' => '/clubs', 'verb' => 'GET'],
        // Backups of the club tables (Nextcloud administrators only)
        ['name' => 'backup#index', 'url' => '/backups', 'verb' => 'GET'],
        ['name' => 'backup#create', 'url' => '/backups', 'verb' => 'POST'],
        ['name' => 'backup#download', 'url' => '/backups/{name}', 'verb' => 'GET', 'requirements' => ['name' => 'verein-backup-[0-9]{8}-[0-9]{6}\.json\.gz']],
        ['name' => 'club#create', 'url' => '/clubs', 'verb' => 'POST'],
        ['name' => 'club#update', 'url' => '/clubs/{clubId}', 'verb' => 'PUT'],
        ['name' => 'club#destroy', 'url' => '/clubs/{clubId}', 'verb' => 'DELETE'],
        ['name' => 'club#updateRoleMapping', 'url' => '/clubs/{clubId}/role-mapping', 'verb' => 'PUT'],
        ['name' => 'club#createFeeRate', 'url' => '/clubs/{clubId}/fee-rates', 'verb' => 'POST'],
        ['name' => 'club#updateFeeRate', 'url' => '/clubs/{clubId}/fee-rates/{rateId}', 'verb' => 'PUT'],
        ['name' => 'club#destroyFeeRate', 'url' => '/clubs/{clubId}/fee-rates/{rateId}', 'verb' => 'DELETE'],
        ['name' => 'club#createAccount', 'url' => '/clubs/{clubId}/accounts', 'verb' => 'POST'],
        ['name' => 'club#updateAccount', 'url' => '/clubs/{clubId}/accounts/{accountId}', 'verb' => 'PUT'],
        ['name' => 'club#destroyAccount', 'url' => '/clubs/{clubId}/accounts/{accountId}', 'verb' => 'DELETE'],

        // Self-service: the person linked to the logged-in Nextcloud account
        ['name' => 'me#index', 'url' => '/me', 'verb' => 'GET'],
        ['name' => 'me#export', 'url' => '/me/export', 'verb' => 'GET'],

        // Members - all of these need a clubId parameter
        ['name' => 'member#index', 'url' => '/members', 'verb' => 'GET'],
        // literal route must come before /members/{id}
        ['name' => 'member#lookup', 'url' => '/members/lookup', 'verb' => 'GET'],
        ['name' => 'member#searchUsers', 'url' => '/members/users', 'verb' => 'GET'],
        ['name' => 'member#attach', 'url' => '/memberships', 'verb' => 'POST'],
        ['name' => 'member#create', 'url' => '/members', 'verb' => 'POST'],
        ['name' => 'member#show', 'url' => '/members/{id}', 'verb' => 'GET'],
        ['name' => 'member#update', 'url' => '/members/{id}', 'verb' => 'PUT'],
        ['name' => 'member#destroy', 'url' => '/members/{id}', 'verb' => 'DELETE'],
        ['name' => 'member#deactivate', 'url' => '/members/{id}/deactivate', 'verb' => 'POST'],
        ['name' => 'member#activate', 'url' => '/members/{id}/activate', 'verb' => 'POST'],
        
        ['name' => 'finance#index', 'url' => '/finance', 'verb' => 'GET'],
        ['name' => 'finance#markPaid', 'url' => '/finance/mark-paid', 'verb' => 'POST'],
        ['name' => 'finance#flagOverdue', 'url' => '/finance/flag-overdue', 'verb' => 'POST'],
        ['name' => 'feeRun#preview', 'url' => '/fee-run/preview', 'verb' => 'POST'],
        ['name' => 'feeRun#run', 'url' => '/fee-run', 'verb' => 'POST'],
        ['name' => 'finance#create', 'url' => '/finance', 'verb' => 'POST'],
        ['name' => 'finance#update', 'url' => '/finance/{id}', 'verb' => 'PUT'],
        ['name' => 'finance#destroy', 'url' => '/finance/{id}', 'verb' => 'DELETE'],
        
        ['name' => 'sepa#export', 'url' => '/sepa/export', 'verb' => 'POST'],
        ['name' => 'sepa#preview', 'url' => '/sepa/preview', 'verb' => 'POST'],

        // Export endpoints
        ['name' => 'export#exportMembersAsCsv', 'url' => '/export/members/csv', 'verb' => 'GET'],
        ['name' => 'export#exportMembersAsPdf', 'url' => '/export/members/pdf', 'verb' => 'GET'],
        ['name' => 'export#exportFeesAsCsv', 'url' => '/export/fees/csv', 'verb' => 'GET'],
        ['name' => 'export#exportFeesAsPdf', 'url' => '/export/fees/pdf', 'verb' => 'GET'],

        // Statistics endpoints
        ['name' => 'statistics#getMemberStatistics', 'url' => '/statistics/members', 'verb' => 'GET'],
        ['name' => 'statistics#getFeeStatistics', 'url' => '/statistics/fees', 'verb' => 'GET'],

        ['name' => 'auditLog#index', 'url' => '/audit-log', 'verb' => 'GET'],

        // RBAC & permissions
        ['name' => 'role#index', 'url' => '/roles', 'verb' => 'GET'],
        ['name' => 'role#store', 'url' => '/roles', 'verb' => 'POST'],
        // literal single-segment routes must come before /roles/{id}, which
        // would otherwise greedily match them (e.g. id='search-users')
        ['name' => 'role#searchUsers', 'url' => '/roles/search-users', 'verb' => 'GET'],
        ['name' => 'role#clubAssignments', 'url' => '/roles/assignments', 'verb' => 'GET'],
        ['name' => 'role#assignRole', 'url' => '/roles/users', 'verb' => 'POST'],
        ['name' => 'role#removeRoles', 'url' => '/roles/users', 'verb' => 'DELETE'],
        ['name' => 'role#indexByClubType', 'url' => '/roles/club/{clubType}', 'verb' => 'GET'],
        ['name' => 'role#getUserRoles', 'url' => '/roles/users/{userId}', 'verb' => 'GET'],
        ['name' => 'role#show', 'url' => '/roles/{id}', 'verb' => 'GET'],
        ['name' => 'role#update', 'url' => '/roles/{id}', 'verb' => 'PUT'],
        ['name' => 'role#destroy', 'url' => '/roles/{id}', 'verb' => 'DELETE'],

        ['name' => 'permission#index', 'url' => '/permissions', 'verb' => 'GET'],

    ]
];
