<?php
/** @var array $_ */
// linkTo() alone does not add any cache-busting query string (unlike the
// script() helper below, which does) - a CSS-only fix would otherwise never
// reach browsers that already cached this file under this same URL.
$styleVersion = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion('verein');
$urlGenerator = \OCP\Server::get(\OCP\IURLGenerator::class);
$styleHref = $urlGenerator->linkTo('verein', 'js/dist/style.css') . '?v=' . urlencode($styleVersion);
// Where this Nextcloud lives ("", "/nextcloud", "/index.php", "/nextcloud/index.php", ...): the app's own route minus
// its "/apps/verein" tail. The frontend prefixes every URL with it (js/absoluteUrl.js), so subfolder installs work.
$urlRoot = preg_replace('#/apps/verein/?$#', '', $urlGenerator->linkToRoute('verein.page.index'));
?>

<link rel="stylesheet" href="<?php echo $styleHref; ?>">

<div id="app" tabindex="0" data-url-root="<?php p($urlRoot); ?>"></div>

<?php
script('verein', 'dist/nextcloud-verein');
?>
