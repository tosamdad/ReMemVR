<?php /** 로그인 계열 화면 뒤의 부드러운 색 번짐(시안의 배경 장식, 화면에 고정). 부모에 relative isolate 가 있어야 한다. */ ?>
<div class="pointer-events-none fixed inset-y-0 left-1/2 -z-10 w-full max-w-[520px] -translate-x-1/2 overflow-hidden" aria-hidden="true">
  <div class="absolute -right-[5%] -top-[10%] h-72 w-72 rounded-full bg-secondary-container opacity-30 blur-[80px]"></div>
  <div class="absolute -bottom-[10%] -left-[5%] h-80 w-80 rounded-full bg-primary-container opacity-30 blur-[100px]"></div>
</div>
