#!/usr/bin/env php
<?php
/**
 * Initialises a test project that can be built by travis.
 *
 * Travis downloads the module, but in order to run unit tests it needs
 * to be part of a SilverStripe "installer" project.
 * This script generates a custom composer.json with the required dependencies
 * and installs it into a separate webroot. The originally downloaded module
 * code is re-installed via composer.
 */

if (php_sapi_name() != 'cli') {
	header('HTTP/1.0 404 Not Found');
	exit;
}

$opts = getopt('', array(
	'source:', // required
	'target:', // required
	'config:',
));

// Sanity checks
if (!$opts) {
	echo "Invalid arguments specified\n";
	exit(1);
}
$requiredEnvs = array('TRAVIS_COMMIT', 'TRAVIS_BRANCH', 'CORE_RELEASE');
foreach($requiredEnvs as $requiredEnv) {
	if(!getenv($requiredEnv)) {
		echo(sprintf('Environment variable "%s" not defined', $requiredEnv));
		exit(1);
	}
}

$dir = __DIR__;
$configPath = (isset($opts['config'])) ? $opts['config'] : null;
$targetPath = $opts['target'];
$modulePath = $opts['source'];
$moduleName = basename($modulePath);
$parent = dirname($modulePath);

// Get exact version of downloaded module so we can re-download via composer
$moduleRevision = getenv('TRAVIS_COMMIT');
$moduleBranch = getenv('TRAVIS_BRANCH');
$moduleBranchComposer = (preg_match('/^\d\.\d/', $moduleBranch)) ? $moduleBranch . '.x-dev' : 'dev-' . $moduleBranch;
$coreBranch = getenv('CORE_RELEASE');
$coreBranchComposer = (preg_match('/^\d\.\d/', $coreBranch)) ? $coreBranch . '.x-dev' : 'dev-' . $coreBranch;

// Print out some environment information.
printf("Environment:\n");
printf("  * MySQL:      %s\n", trim(`mysql --version`));
printf("  * PostgreSQL: %s\n", trim(`pg_config --version`));
printf("  * SQLite:     %s\n\n", trim(`sqlite3 -version`));

// Extract the package info from the module composer file, and build a
// custom project composer file with the local package explicitly defined.
echo "Reading composer information...\n";
if(!file_exists("$modulePath/composer.json")) {
	echo('File not found: $modulePath/composer.json');
	exit(1);
}
$package = json_decode(file_get_contents("$modulePath/composer.json"), true);

// Override the default framework requirement with the one being built.
$package += array(
	'version' => $moduleBranchComposer,
	'dist' => array(
		'type' => 'tar',
		'url' => "file://$parent/$moduleName.tar"
	)
);

// Generate a custom composer file.
$composer = array(
	'repositories' => array(array('type' => 'package', 'package' => $package)),
	'require' => array_merge(
		isset($package['require']) ? $package['require'] : array(),
		array($package['name'] => $moduleBranchComposer)
	),
	// Always include DBs, allow module specific version dependencies though
	'require-dev' => array_merge(
		array('silverstripe/postgresql' => '*','silverstripe/sqlite3' => '*'),
		isset($package['require-dev']) ? $package['require-dev'] : array()
	),
	'minimum-stability' => 'dev',
	'config' => array(
		'notify-on-install' => false
	)
);

// Framework and CMS need special treatment for version dependencies
if(in_array($package['name'], array('silverstripe/cms', 'silverstripe/framework'))) {
	// $composer['repositories'][0]['package']['version'] = $coreBranchComposer;
	$composer['require'][$package['name']] .= ' as ' . $coreBranchComposer;
}

// 2.x based installs need a custom path
if(preg_match('/^\d\.\d/', $coreBranch) && version_compare($coreBranch, '3.0') == -1) {
	$composer["extra"] = array(
		"installer-paths" => array(
			"sapphire" => array("silverstripe/framework")
		)
	);
}

// Override module dependencies in order to test with specific core branch.
// This might be older than the latest permitted version based on the module definition.
// Its up to the module author to declare compatible CORE_RELEASE values in the .travis.yml.
// Leave dependencies alone if we're testing either of those modules directly.
if(isset($composer['require']['silverstripe/framework']) && $package['name'] != 'silverstripe/framework') {
	$composer['require']['silverstripe/framework'] = $coreBranchComposer;
}
if(isset($composer['require']['silverstripe/cms']) && $package['name'] != 'silverstripe/cms') {
	$composer['require']['silverstripe/cms'] = $coreBranchComposer;
}
$composer = json_encode($composer);

echo "Generated composer file:\n";
echo "$composer\n\n";

echo "Archiving $moduleName...\n";
`cd $modulePath`;
`tar -cf $parent/$moduleName.tar .`;

echo "Cloning installer@$coreBranch...\n";
`git clone --depth=100 --quiet -b $coreBranch git://github.com/silverstripe/silverstripe-installer.git $targetPath`;

// Installer doesn't work out of the box without cms - delete the Page class if its not required
if(!isset($composer['require']['silverstripe/cms']) && file_exists("$targetPath/mysite/code/Page.php")) {
	unlink("$targetPath/mysite/code/Page.php");
}

echo "Setting up project...\n";
`cp $dir/_ss_environment.php $targetPath/_ss_environment.php`;
if($configPath) `cp $configPath $targetPath/mysite/_config.php`;

echo "Replacing composer file...\n";
unlink("$targetPath/composer.json");
file_put_contents("$targetPath/composer.json", $composer);

echo "Running composer...\n";
passthru("composer install --prefer-dist --dev -d $targetPath");