<?php
/** @var array $_ */
// linkTo() alone does not add any cache-busting query string (unlike the
// script() helper below, which does) - a CSS-only fix would otherwise never
// reach browsers that already cached this file under this same URL.
$styleVersion = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion('verein');
$styleHref = \OCP\Server::get(\OCP\IURLGenerator::class)->linkTo('verein', 'js/dist/style.css') . '?v=' . urlencode($styleVersion);
?>

<link rel="stylesheet" href="<?php echo $styleHref; ?>">

<div id="app" tabindex="0"></div>

<?php
script('verein', 'dist/nextcloud-verein');
?>
