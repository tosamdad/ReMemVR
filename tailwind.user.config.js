/**
 * 회원(모바일 웹) 화면 디자인 시스템: Luminous Storyteller (Google Stitch 시안)
 * 색은 CSS 변수로 정의해 다크 모드에서 변수만 바꾼다(src/css/user.css).
 */
const tokens = [
  'primary', 'on-primary', 'primary-container', 'on-primary-container', 'primary-fixed', 'primary-fixed-dim',
  'on-primary-fixed', 'on-primary-fixed-variant', 'inverse-primary',
  'secondary', 'on-secondary', 'secondary-container', 'on-secondary-container', 'secondary-fixed', 'secondary-fixed-dim',
  'on-secondary-fixed', 'on-secondary-fixed-variant',
  'tertiary', 'on-tertiary', 'tertiary-container', 'on-tertiary-container', 'tertiary-fixed', 'tertiary-fixed-dim',
  'on-tertiary-fixed', 'on-tertiary-fixed-variant',
  'error', 'on-error', 'error-container', 'on-error-container',
  'background', 'on-background', 'surface', 'on-surface', 'surface-variant', 'on-surface-variant',
  'surface-bright', 'surface-dim', 'surface-tint',
  'surface-container-lowest', 'surface-container-low', 'surface-container', 'surface-container-high', 'surface-container-highest',
  'inverse-surface', 'inverse-on-surface', 'outline', 'outline-variant',
];
const colors = Object.fromEntries(tokens.map((t) => [t, `rgb(var(--c-${t}) / <alpha-value>)`]));

const head = ['"Plus Jakarta Sans"', '"Pretendard Variable"', 'Pretendard', '"Apple SD Gothic Neo"', '"Noto Sans KR"', 'sans-serif'];
const body = ['"Nunito Sans"', '"Pretendard Variable"', 'Pretendard', '"Apple SD Gothic Neo"', '"Noto Sans KR"', 'sans-serif'];

module.exports = {
  darkMode: 'class',
  content: ['./server/views/user/**/*.php', './server/views/errors/**/*.php', './public/assets/js/**/*.js'],
  theme: {
    extend: {
      colors,
      borderRadius: { DEFAULT: '0.25rem', lg: '0.5rem', xl: '0.75rem', '2xl': '1rem', '3xl': '1.5rem', full: '9999px' },
      spacing: {
        xs: '4px', sm: '12px', base: '8px', md: '24px', lg: '48px', xl: '80px',
        'margin-mobile': '16px', gutter: '20px', 'gutter-mobile': '16px', 'margin-desktop': '64px',
      },
      fontFamily: {
        'headline-xl': head, 'headline-xl-mobile': head, 'headline-lg': head, 'headline-md': head,
        'body-lg': body, 'body-md': body, 'label-lg': body, 'label-sm': body,
        sans: body,
        story: ['"Gowun Dodum"', '"Pretendard Variable"', 'Pretendard', 'sans-serif'],
      },
      fontSize: {
        'headline-xl': ['48px', { lineHeight: '56px', letterSpacing: '-0.02em', fontWeight: '700' }],
        'headline-xl-mobile': ['32px', { lineHeight: '40px', letterSpacing: '-0.01em', fontWeight: '700' }],
        'headline-lg': ['32px', { lineHeight: '40px', fontWeight: '600' }],
        'headline-md': ['24px', { lineHeight: '32px', fontWeight: '600' }],
        'body-lg': ['18px', { lineHeight: '28px', fontWeight: '400' }],
        'body-md': ['16px', { lineHeight: '24px', fontWeight: '400' }],
        'label-lg': ['14px', { lineHeight: '20px', letterSpacing: '0.02em', fontWeight: '700' }],
        'label-sm': ['12px', { lineHeight: '16px', fontWeight: '600' }],
      },
      boxShadow: {
        soft: '0 20px 40px rgba(0,0,0,0.04)',
        nav: '0 -4px 20px 0 rgba(128,80,98,0.05)',
      },
    },
  },
  plugins: [require('@tailwindcss/forms'), require('@tailwindcss/container-queries')],
};
