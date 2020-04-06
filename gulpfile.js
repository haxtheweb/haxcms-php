const gulp = require("gulp");
const terser = require("gulp-terser");
const glob = require("glob");
const fs = require("fs");
const path = require("path");
gulp.task(
  "terser", () => {
    // now work on all the other files
    gulp.src('./build/es6/**/*.js')
    .pipe(terser({
        ecma: 2017,
        keep_fnames: true,
        mangle: true,
        module: true,
      }))
      .pipe(gulp.dest('./build/es6/'));
    // now work on all the other files
    return gulp.src('./build/es6-amd/**/*.js')
    .pipe(terser({
        keep_fnames: true,
        mangle: true,
        module: false,
        safari10: true,
      }))
      .pipe(gulp.dest('./build/es6-amd/'));
  }
);

gulp.task("wc-autoloader", async () => {
  glob(path.join("./build/es6/**/*.js"), (er, files) => {
    let elements = [];
    // async loop over files
    files.forEach((file) => {
      // first check if this is a file we need to get.
      if (file.includes("node_modules")) {
        // grab the name of the file
        const fName = file.replace("node_modules/", "");

        if (fs.existsSync(file)) {
          const contents = fs.readFileSync(file, "utf8");
          // This Regex is looking for tags that are defined by string values
          // this will work for customElements.define("local-time",s))
          // This will NOT work for customElements.define(LocalTime.tagName,s))
          const defineStatements = /customElements\.define\(["|'|`](.*?)["|'|`]/gm.exec(
            contents
          );
          if (defineStatements) {
            const tagName = defineStatements[1];
            elements.push({ [tagName]: fName });
          }
        }
      }
    });

    // write entries to file
    // use null, 2 to format JSON
    fs.writeFileSync(
      path.join(__dirname, "wc-autoloader.json"),
      JSON.stringify(elements, null, 4),
      "utf8"
    );
  });
});
