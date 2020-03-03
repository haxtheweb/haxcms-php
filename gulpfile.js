const gulp = require('gulp');
const terser = require('gulp-terser');
const babel = require('gulp-babel');
const customMinifyCss = require('@open-wc/building-utils/custom-minify-css');
gulp.task(
  "default", () => {
    // now work on all the other files
    gulp.src('./build/es6/**/*.js')
    .pipe(babel({
      plugins: ['@babel/plugin-syntax-import-meta',["template-html-minifier", {
        modules: {
          'lit-html': ['html'],
          'lit-element': ['html', { name: 'css', encapsulation: 'style' }],
        },
        htmlMinifier: {
          collapseWhitespace: true,
          conservativeCollapse: true,
          removeComments: true,
          caseSensitive: true,
          minifyCSS: customMinifyCss,
        },
      }]]
    }))
    .pipe(terser({
        ecma: 2017,
        keep_fnames: true,
        mangle: true,
        module: true,
      }))
      .pipe(gulp.dest('./build/es6/'));
    // now work on all the other files
    return gulp.src('./build/es6-amd/**/*.js')
    .pipe(babel({
      plugins: ['@babel/plugin-syntax-import-meta',["template-html-minifier",{
        modules: {
          'lit-html': ['html'],
          'lit-element': ['html', { name: 'css', encapsulation: 'style' }],
        },
        htmlMinifier: {
          collapseWhitespace: true,
          conservativeCollapse: true,
          removeComments: true,
          caseSensitive: true,
          minifyCSS: customMinifyCss,
        },
      }]]
    }))
    .pipe(terser({
        keep_fnames: true,
        mangle: true,
        module: false,
        safari10: true,
      }))
      .pipe(gulp.dest('./build/es6-amd/'));
  }
);