<?php
/**
 * 로그인, 회원가입처럼 하단 탭이 없는 회원 화면 레이아웃. 변수: title
 * 본문은 layout('user/layout', ['showNav' => false, 'header' => 'none']) 과 같지만 가운데 정렬 여백을 준다.
 */
layout('user/layout', ['title' => isset($title) ? $title : '', 'showNav' => false, 'header' => 'none', 'mainClass' => 'px-margin-mobile pt-10 pb-12']);
?>
<?= $content ?>
