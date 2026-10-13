import js from '@eslint/js';
import prettier from 'eslint-config-prettier';
import jsdoc from 'eslint-plugin-jsdoc';
import globals from 'globals';

export default [
    {
        ignores: ['vendor/**', 'node_modules/**', 'public/**', 'storage/**', 'bootstrap/cache/**'],
    },
    js.configs.recommended,
    {
        files: ['resources/js/**/*.js', 'tests/js/**/*.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: globals.browser,
        },
        plugins: { jsdoc },
        rules: {
            // The project documents exported code with JSDoc, explaining the why.
            'jsdoc/require-jsdoc': [
                'error',
                {
                    publicOnly: true,
                    require: { FunctionDeclaration: true, ClassDeclaration: true, MethodDefinition: true },
                },
            ],
            'jsdoc/check-param-names': 'error',
            'jsdoc/check-types': 'error',
            eqeqeq: ['error', 'always'],
            'no-var': 'error',
            'prefer-const': 'error',
        },
    },
    {
        // Tests describe themselves through their titles; helpers need no JSDoc.
        files: ['tests/js/**/*.js'],
        rules: { 'jsdoc/require-jsdoc': 'off' },
    },
    {
        // Tooling configuration files run in Node, not in the browser.
        files: ['*.config.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: globals.node,
        },
    },
    prettier,
];
