import colors from 'tailwindcss/colors';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/js/**/*.{vue,js}',
        './resources/views/**/*.blade.php',
    ],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                // Neutral zinc reads crisper than the default blue-tinted gray.
                gray: {
                    ...colors.zinc,
                    850: '#1f1f23',
                    925: '#111114',
                    950: '#0a0a0c',
                },
                primary: {
                    50: '#f1f0ff',
                    100: '#e4e2ff',
                    200: '#cdc9ff',
                    300: '#aba3ff',
                    400: '#8b7dff',
                    500: '#6f5cf6',
                    600: '#5b45e8',
                    700: '#4c37c9',
                    800: '#3f2fa2',
                    900: '#352b80',
                    950: '#211a4c',
                },
            },
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', '"Helvetica Neue"', 'Arial', 'sans-serif'],
                mono: ['"JetBrains Mono"', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'monospace'],
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.03)',
                pop: '0 10px 30px -10px rgb(0 0 0 / 0.25), 0 2px 6px -2px rgb(0 0 0 / 0.08)',
            },
            fontSize: {
                '2xs': ['0.6875rem', { lineHeight: '1rem' }],
            },
            maxWidth: {
                shell: '88rem',
            },
        },
    },
    plugins: [],
};
