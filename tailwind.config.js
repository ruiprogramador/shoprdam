import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                indigo: {
                    50:  '#f0f5ff',
                    100: '#dbeafe',
                    400: '#3b82f6',
                    500: '#1c63e5',
                    600: '#3b82f6',
                    700: '#1c63e5',
                    800: '#1449b0',
                    900: '#0f3580',
                },
                // Ou mais limpo — adiciona uma cor "primary"
                primary: {
                    50:  '#f0f5ff',
                    100: '#dbeafe',
                    400: '#3b82f6',
                    500: '#1c63e5',
                    600: '#1c63e5',
                    700: '#1449b0',
                },
            },
        },
    },

    plugins: [forms],
};
