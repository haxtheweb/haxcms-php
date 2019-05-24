const gulp = require('gulp');
const terser = require('gulp-terser');
gulp.task(
  "default", async () => {
    await gulp.src('./build/es6-amd/**/*.js')
      .pipe(terser({
        keep_fnames: true,
        mangle: false,
        compress: true,
        module: true
      }))
      .pipe(gulp.dest('./build/es6-amd/'));
    return await gulp.src('./build/es6/**/*.js')
      .pipe(terser({
        keep_fnames: true,
        mangle: false,
        compress: true,
        module: true
      }))
      .pipe(gulp.dest('./build/es6/'));
  }
);