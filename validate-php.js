const fs = require('fs');
const parser = require('php-parser');

const engine = new parser.Engine({
	parser: { extractDoc: false, suppressErrors: false },
	ast: { withPositions: true }
});

const files = process.argv.slice(2);
let failed = 0;

for (const file of files) {
	try {
		const code = fs.readFileSync(file, 'utf8');
		engine.parseCode(code, file);
		console.log('OK   ' + file);
	} catch (e) {
		failed++;
		console.log('FAIL ' + file);
		console.log('     ' + e.message);
	}
}

process.exit(failed ? 1 : 0);
