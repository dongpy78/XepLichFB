const mix = require('laravel-mix');

const path = require('path');
const directory = path.basename(path.resolve(__dirname));

const source = 'platform/plugins/' + directory;
const dist = 'public/vendor/core/plugins/' + directory;

mix
    .js(source + '/resources/js/currencies.js', dist + '/js')
    .js(source + '/resources/js/booking-calendar.js', dist + '/js')
    .sass(source + '/resources/css/currencies.scss', dist + '/css')
    .sass(source + '/resources/css/booking-calendar.scss', dist + '/css')

if (mix.inProduction()) {
    mix.copy(dist + '/js/currencies.js', source + '/public/js')
       .copy(dist + '/js/booking-calendar.js', source + '/public/js')
       .copy(dist + '/css/currencies.css', source + '/public/css')
       .copy(dist + '/css/booking-calendar.css', source + '/public/css');
}