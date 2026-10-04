<?php /** 로그인 계열 화면 뒤의 부드러운 색 번짐(시안의 배경 장식). 부모에 relative isolate 가 있어야 한다. */ ?>
<div class="pointer-events-none absolute -inset-x-margin-mobile -top-10 -bottom-12 -z-10 overflow-hidden" aria-hidden="true">
  <div class="absolute -right-10 -top-6 h-72 w-72 rounded-full bg-secondary-container opacity-30 blur-[80px]"></div>
  <div class="absolute -bottom-10 -left-10 h-80 w-80 rounded-full bg-primary-container opacity-30 blur-[100px]"></div>
</div>
