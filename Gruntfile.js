module.exports = function(grunt) {
    grunt.initConfig({
        linter: {
            files: ['metaman.js'],
            directives: {
                browser: true,
            },
            globals: {
                jQuery: true
            }
        },
        watch: {
            files: ['metaman.js'],
            tasks: 'linter'
        }
    });
    grunt.loadNpmTasks('grunt-linter');
    grunt.loadNpmTasks('grunt-contrib-watch');
    grunt.registerTask('default', ['linter']);
};