const gulp = require("gulp");
const terser = require("gulp-terser");
const { glob } = require("glob");
const fs = require("fs");
const path = require("path");
// mirror version numbers
gulp.task(
  "version-match", async () => {
    const packageVm = require("./node_modules/@lrnwebcomponents/haxcms-elements/package.json");
    fs.writeFileSync('./VERSION.txt', packageVm.version , {encoding:'utf8',flag:'w'});
    console.log(`${packageVm.version} written to VERSION.txt`);
  }
);
gulp.task(
  "terser", () => {
    // now work on all the other files
    return gulp.src([
      './build/es6/**/*.js'
    ]).pipe(terser({
        ecma: 2018,
        keep_fnames: true,
        mangle: true,
        module: true,
      }))
      .pipe(gulp.dest('./build/es6/'));
  }
);
// https://html.spec.whatwg.org/multipage/scripting.html#valid-custom-element-name
const reservedNames = new Set([
	'annotation-xml',
	'color-profile',
	'font-face',
	'font-face-src',
	'font-face-uri',
	'font-face-format',
	'font-face-name',
	'missing-glyph'
]);

function hasError(name) {
	if (!name) {
		return 'Missing element name.';
	}

	if (/[A-Z]/.test(name)) {
		return 'Custom element names must not contain uppercase ASCII characters.';
	}

	if (!name.includes('-')) {
		return 'Custom element names must contain a hyphen. Example: unicorn-cake';
	}

	if (/^\d/i.test(name)) {
		return 'Custom element names must not start with a digit.';
	}

	if (/^-/i.test(name)) {
		return 'Custom element names must not start with a hyphen.';
	}

	if (reservedNames.has(name)) {
		return 'The supplied element name is reserved and can\'t be used.\nSee: https://html.spec.whatwg.org/multipage/scripting.html#valid-custom-element-name';
	}
}

function validateElementName(name) {
	const errorMessage = hasError(name);
	return !errorMessage;
}
gulp.task("wc-autoloader", async () => {
  glob(path.join("./build/es6/node_modules/**/*.js"), (er, files) => {
    let elements = {};
    // async loop over files
    files.forEach((file) => {
      // grab the name of the file
      if (fs.existsSync(file)) {
        let fLocation = file.replace("build/es6/node_modules/", "");
        const contents = fs.readFileSync(file, "utf8");
        // This Regex is looking for tags that are defined by string values
        // this will work for customElements.define("local-time",s))
        // This will NOT work for customElements.define(LocalTime.tagName,s))
        const defineStatements = /customElements\.define\(["|'|`](.*?)["|'|`]/gm.exec(
          contents
        );
        // basic
        if (defineStatements && validateElementName(defineStatements[1])) {
          elements[defineStatements[1]] = fLocation;
        }
        // .tag calls
        else {
          const hasDefine = /customElements\.define\((.*?),(.*?)\)/gm.exec(
            contents
          );
          // check for a define still
          if (hasDefine && hasDefine[1] && hasDefine[1].includes('.tag')) {
            const tagStatements = /static get tag\(\){return"(.*?)"}/gm.exec(
              contents
            );
            if (tagStatements && validateElementName(tagStatements[1])) {
              elements[tagStatements[1]] = fLocation;
            }
          }
          else if (hasDefine && hasDefine[1] && hasDefine[1].includes('.is')) {
            const tagStatements = /static get is\(\){return"(.*?)"}/gm.exec(
              contents
            );
            if (tagStatements && validateElementName(tagStatements[1])) {
              elements[tagStatements[1]] = fLocation;
            }
          }
          else {
            if (!hasDefine) {
              // support for polymer legacy class housing
              const PolymerLegacy = /is\:\"(.*?)\"/gm.exec(
                contents
              );
              if (PolymerLegacy && PolymerLegacy[1] && validateElementName(PolymerLegacy[1])) {
                elements[PolymerLegacy[1]] = fLocation;
              }
              else {
                // if we got here, it wasn't a file w/ a custom element definition
                // so it's not an entry point
              }
            }
          }
        }
      }
    });

    // write entries to file
    fs.writeFileSync(
      path.join(__dirname, "wc-registry.json"),
      JSON.stringify(elements),
      {encoding:'utf8',flag:'w'}
    );
    // write entries to demo for local work
    fs.writeFileSync(
      "./dist/wc-registry.json",
      JSON.stringify(elements),
      {encoding:'utf8',flag:'w'}
    );
    // write entries to demo for local work
    fs.writeFileSync(
      "../lrnwebcomponents/elements/haxcms-elements/demo/wc-registry.json",
      JSON.stringify(elements),
      {encoding:'utf8',flag:'w'}
    );
  });
});
