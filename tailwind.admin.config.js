/**
 * 관리자(백오피스) 화면 디자인 시스템: Kindred Audio (Google Stitch 시안)
 */
const head = ['"Plus Jakarta Sans"', '"Pretendard Variable"', 'Pretendard', '"Apple SD Gothic Neo"', '"Noto Sans KR"', 'sans-serif'];
const body = ['"Nunito Sans"', '"Pretendard Variable"', 'Pretendard', '"Apple SD Gothic Neo"', '"Noto Sans KR"', 'sans-serif'];

module.exports = {
  content: ['./server/views/admin/**/*.php', './server/views/errors/**/*.php', './public/assets/js/admin*.js', './public/assets/js/app.js'],
  theme: {
    extend: {
      colors: {
        'on-background': '#1b1c1c', 'surface-container': '#efeded', 'error-container': '#ffdad6', 'on-surface-variant': '#41484e',
        background: '#fbf9f8', 'on-tertiary-fixed': '#1e1b13', 'primary-fixed': '#cae6ff', 'surface-variant': '#e4e2e2',
        'inverse-surface': '#303030', 'surface-container-highest': '#e4e2e2', 'tertiary-fixed-dim': '#cdc6b8',
        'secondary-fixed-dim': '#ffb95a', 'on-surface': '#1b1c1c', primary: '#1c648e', 'inverse-primary': '#90cdfd',
        'surface-tint': '#1c648e', 'secondary-container': '#feb246', 'on-tertiary-container': '#49453a', tertiary: '#635e53',
        'surface-bright': '#fbf9f8', 'on-tertiary': '#ffffff', 'tertiary-container': '#b9b2a4', 'surface-container-low': '#f5f3f3',
        'on-primary-fixed-variant': '#004b70', 'primary-container': '#7cb9e8', 'on-tertiary-fixed-variant': '#4b463c',
        'on-secondary': '#ffffff', 'on-primary-fixed': '#001e30', 'surface-container-high': '#eae8e7', 'surface-dim': '#dbd9d9',
        'on-secondary-container': '#6f4600', 'inverse-on-surface': '#f2f0f0', 'surface-container-lowest': '#ffffff',
        'tertiary-fixed': '#e9e2d3', error: '#ba1a1a', 'primary-fixed-dim': '#90cdfd', 'on-secondary-fixed-variant': '#643f00',
        'on-error': '#ffffff', 'on-secondary-fixed': '#2a1800', outline: '#71787f', 'on-primary-container': '#00496d',
        secondary: '#845400', 'on-primary': '#ffffff', 'outline-variant': '#c0c7cf', 'on-error-container': '#93000a',
        'secondary-fixed': '#ffddb6', surface: '#fbf9f8',
      },
      borderRadius: { DEFAULT: '0.25rem', lg: '0.5rem', xl: '0.75rem', '2xl': '1rem', full: '9999px' },
      spacing: { 'margin-mobile': '20px', 'gutter-mobile': '16px', 'touch-target-min': '48px', base: '8px', 'card-padding': '24px' },
      fontFamily: {
        'body-md': body, 'body-lg': body, 'headline-lg-mobile': head, 'headline-md': head, 'headline-lg': head,
        'label-md': head, 'label-sm': head, sans: body,
      },
      fontSize: {
        'body-md': ['16px', { lineHeight: '24px', fontWeight: '400' }],
        'headline-lg-mobile': ['26px', { lineHeight: '32px', letterSpacing: '-0.01em', fontWeight: '700' }],
        'headline-md': ['24px', { lineHeight: '30px', fontWeight: '600' }],
        'label-md': ['14px', { lineHeight: '20px', letterSpacing: '0.02em', fontWeight: '600' }],
        'body-lg': ['18px', { lineHeight: '28px', fontWeight: '400' }],
        'label-sm': ['12px', { lineHeight: '16px', letterSpacing: '0.05em', fontWeight: '700' }],
        'headline-lg': ['32px', { lineHeight: '40px', letterSpacing: '-0.02em', fontWeight: '700' }],
      },
      boxShadow: {
        card: '0 4px 24px rgba(0,0,0,0.03)',
        bar: '0 1px 8px rgba(0,0,0,0.03)',
      },
    },
  },
  plugins: [require('@tailwindcss/forms'), require('@tailwindcss/container-queries')],
};
