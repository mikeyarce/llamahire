import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync, spawnSync } from 'node:child_process';

// Reproduce the supplied blueprint in a fresh Playground, never the Studio site.
const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const scratch = await mkdtemp( join( tmpdir(), 'llamahire-wpjm-playground-' ) );
const blueprintUrl = 'https://wordpress.org/plugins/wp-json/plugins/v1/plugin/wp-job-manager/blueprint.json?rev=3679700&lang=en_US';
const response = await fetch( blueprintUrl );
if ( ! response.ok ) throw new Error( `Blueprint fetch failed: ${response.status}` );
const blueprint = await response.json();
const snapshot = blueprint.steps.find( ( step ) => step.step === 'importWordPressFiles' );
if ( ! snapshot || snapshot.wordPressFilesZip.url !== 'https://wpjobmanager.com/wp-content/uploads/2024/03/playground-5.zip' ) {
	throw new Error( 'The upstream blueprint changed; review its snapshot before updating this test.' );
}
await writeFile( join( scratch, 'upstream-blueprint.json' ), JSON.stringify( blueprint, null, 2 ) );
const snapshotResponse = await fetch( snapshot.wordPressFilesZip.url );
if ( ! snapshotResponse.ok ) throw new Error( `Snapshot fetch failed: ${snapshotResponse.status}` );
await writeFile( join( scratch, 'upstream-snapshot.zip' ), Buffer.from( await snapshotResponse.arrayBuffer() ) );
// The 2024 archive contains macOS metadata that current Playground cannot copy.
// Remove only Finder metadata; WordPress files and SQLite bytes stay identical.
execFileSync( 'python3', [ '-c', `
import copy, pathlib, sys, zipfile
base = pathlib.Path(sys.argv[1])
with zipfile.ZipFile(base / 'upstream-snapshot.zip') as source, zipfile.ZipFile(base / 'snapshot.zip', 'w', zipfile.ZIP_DEFLATED) as target:
    for entry in source.infolist():
        if entry.filename.startswith('__MACOSX/') or pathlib.PurePosixPath(entry.filename).name == '.DS_Store':
            continue
        target.writestr(copy.copy(entry), source.read(entry.filename))
with zipfile.ZipFile(base / 'upstream-snapshot.zip') as source, zipfile.ZipFile(base / 'snapshot.zip') as target:
    assert source.read('wp-content/database/.ht.sqlite') == target.read('wp-content/database/.ht.sqlite')
    target.extractall(base / 'site')
`, scratch ] );
// Current CLI's importWordPressFiles fails moving this legacy wp-content archive.
// Mount the same extracted files before boot instead; all source records remain.
blueprint.steps.splice( blueprint.steps.indexOf( snapshot ), 1 );
const zip = join( scratch, 'llamahire.zip' );
execFileSync( 'python3', [ '-c', `
import pathlib, sys, zipfile
root = pathlib.Path(sys.argv[1])
with zipfile.ZipFile(sys.argv[2], 'w', zipfile.ZIP_DEFLATED) as archive:
    for name in ['llamahire.php', 'uninstall.php', 'readme.txt', 'includes', 'assets', 'blocks', 'build', 'templates', 'patterns', 'languages', 'tests/wpjm-playground.php']:
        path = root / name
        for file in ([path] if path.is_file() else sorted(path.rglob('*'))):
            if file.is_file():
                archive.write(file, 'llamahire/' + str(file.relative_to(root)))
`, root, zip ] );
blueprint.steps.push(
	{ step: 'installPlugin', pluginData: { resource: 'bundled', path: 'llamahire.zip' }, options: { activate: true } },
	{ step: 'wp-cli', command: 'wp eval-file /wordpress/wp-content/plugins/llamahire/tests/wpjm-playground.php' }
);
const testBlueprint = join( scratch, 'test-blueprint.json' );
await writeFile( testBlueprint, JSON.stringify( blueprint, null, 2 ) );
const logPath = join( scratch, 'run.log' );
const result = spawnSync( process.execPath, [
	join( root, 'node_modules/@wp-playground/cli/wp-playground.js' ), 'run-blueprint',
	`--blueprint=${testBlueprint}`, '--blueprint-may-read-adjacent-files',
	`--php=${blueprint.preferredVersions.php}`, `--wp=${blueprint.preferredVersions.wp}`,
	`--mount=${scratch}:/migration-results`,
	`--mount-before-install=${join( scratch, 'site/wp-content' )}:/wordpress/wp-content`
], { cwd: root, encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 } );
await writeFile( logPath, `${result.stdout || ''}\n${result.stderr || ''}` );
console.log( `Playground evidence: ${scratch}` );
if ( result.error || result.status !== 0 ) {
	console.error( ( result.stderr || result.stdout || String( result.error ) ).slice( -6000 ) );
	process.exit( 1 );
}
console.log( await readFile( join( scratch, 'report.json' ), 'utf8' ) );
