<?php
/**
 * SPDX-FileCopyrightText: 2025 Wacken2012 and the nextcloud-verein contributors <https://github.com/Wacken2012/nextcloud-verein>
 * SPDX-FileCopyrightText: 2026 The Nextcloud Vereinsverwaltung contributors <https://github.com/ghostsailorgit/nextcloud-vereinsverwaltung>
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace OCA\Verein\AppInfo;

use OCA\Verein\Calendar\ClubCalendarProvider;
use OCA\Verein\Db\RoleMapper;
use OCA\Verein\Db\UserRoleMapper;
use OCA\Verein\Db\MemberMapper;
use OCA\Verein\Db\FeeMapper;
use OCA\Verein\Db\ClubMapper;
use OCA\Verein\Db\FeeRateMapper;
use OCA\Verein\Db\MembershipMapper;
use OCA\Verein\Middleware\AuthorizationMiddleware;
use OCA\Verein\Service\RBAC\RoleService;
use OCA\Verein\Service\Export\CsvExporter;
use OCA\Verein\Service\Export\PdfExporter;
use OCA\Verein\Service\MemberService;
use OCA\Verein\Service\FeeService;
use OCA\Verein\Service\AuditLogService;
use OCA\Verein\Service\Clock;
use OCA\Verein\Service\StatisticsService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
    public const APP_ID = 'verein';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerService(RoleService::class, function (IAppContainer $container): RoleService {
            return new RoleService(
                $container->query(RoleMapper::class),
                $container->query(UserRoleMapper::class),
                $container->query(IGroupManager::class),
                $container->query(IUserSession::class),
                $container->query(LoggerInterface::class),
                $container->query(MemberMapper::class),
                $container->query(MembershipMapper::class),
                $container->query(ClubMapper::class),
                $container->query(AuditLogService::class),
                $container->query(IL10N::class)
            );
        });

        $context->registerService(AuthorizationMiddleware::class, function (IAppContainer $container): AuthorizationMiddleware {
            return new AuthorizationMiddleware(
                $container->query(RoleService::class),
                $container->query(IUserSession::class),
                $container->query(LoggerInterface::class),
                $container->query(\OCP\IRequest::class),
                $container->query(IL10N::class)
            );
        });

        // Register export services
        $context->registerService(CsvExporter::class, function (IAppContainer $container): CsvExporter {
            return new CsvExporter($container->query(IL10N::class));
        });

        $context->registerService(PdfExporter::class, function (IAppContainer $container): PdfExporter {
            return new PdfExporter();
        });

        // Register member and fee services
        $context->registerService(MemberService::class, function (IAppContainer $container): MemberService {
            return new MemberService(
                $container->query(MemberMapper::class),
                $container->query(MembershipMapper::class),
                $container->query(FeeMapper::class),
                $container->query(ClubMapper::class),
                $container->query(IUserManager::class),
                $container->query(FeeRateMapper::class),
                $container->query(AuditLogService::class),
                $container->query(IL10N::class)
            );
        });

        $context->registerService(FeeService::class, function (IAppContainer $container): FeeService {
            return new FeeService(
                $container->query(FeeMapper::class),
                $container->query(MembershipMapper::class),
                $container->query(AuditLogService::class),
                $container->query(Clock::class),
                $container->query(IL10N::class)
            );
        });

        $context->registerService(StatisticsService::class, function (IAppContainer $container): StatisticsService {
            return new StatisticsService(
                $container->query(MemberMapper::class),
                $container->query(FeeMapper::class),
                $container->query(Clock::class),
                $container->query(IL10N::class)
            );
        });

        $context->registerMiddleware(AuthorizationMiddleware::class);
        // birthday/anniversary calendar per club via the public calendar-provider API (see ClubCalendarProvider)
        $context->registerCalendarProvider(ClubCalendarProvider::class);
    }

    public function boot(IBootContext $context): void {
    }
}
