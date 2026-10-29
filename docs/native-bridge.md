앱 연결 규약(아이 질문, 폰 안 음성 인식)

르멤버 하이브리드 앱(WebView)이 웹 화면에 넣어 주는 연결 객체의 약속이다. 아이 질문(끼어들기)은 이 객체가 있을 때만 화면에 나타난다. 모바일웹(일반 브라우저)에는 객체가 없으므로 마이크 버튼이 보이지 않는다.


1. 원칙

- 아이 음성은 휴대폰 밖으로 보내지 않는다. 음성 인식은 반드시 기기 안에서 한다.
  - iOS: `SFSpeechRecognizer`, `SFSpeechAudioBufferRecognitionRequest.requiresOnDeviceRecognition = true`. 기기가 지원하지 않으면(`supportsOnDeviceRecognition == false`) 연결 객체를 넣지 않는다.
  - Android: `SpeechRecognizer.createOnDeviceSpeechRecognizer()`(Android 12 이상). `SpeechRecognizer.isOnDeviceRecognitionAvailable()` 이 false 면 연결 객체를 넣지 않는다.
- 녹음 데이터와 인식 중간 결과를 파일로 남기지 않는다.
- 웹 화면은 인식된 글만 `POST /api/play-sessions/{id}/question` 으로 보낸다(본문 `question`, `sentence_seq`, `position_ms`).


2. 연결 객체

앱은 페이지 스크립트보다 먼저 `window.RememvrNative` 를 넣는다(iOS `WKUserScript` atDocumentStart, Android `addJavascriptInterface` 와 초기 스크립트). 늦게 넣었다면 `window.dispatchEvent(new Event('rememvr:native-ready'))` 를 보내면 화면이 버튼을 다시 확인한다.

```js
window.RememvrNative = {
  // 필수. 마이크를 켜고 기기 안에서 인식을 시작한다.
  // 아이가 말을 마치면(침묵 silenceStopMs, 최대 maxMs, 또는 stopListening 호출) 최종 글로 끝난다.
  startListening(options) /* : Promise<{ text: string }> */,
  // 필수. 화면의 마이크 버튼을 다시 누르면 부른다. 지금까지 인식한 글로 startListening 을 끝낸다.
  stopListening() /* : void */,
  // 필수. 질문 창을 닫으면 부른다. 인식을 버리고 startListening 을 { code: 'cancelled' } 로 실패시킨다.
  cancelListening() /* : void */,
};
```

options 항목
- lang: 'ko-KR'
- onDevice: 항상 true. 기기 안 인식을 쓸 수 없으면 { code: 'unavailable' } 로 실패시킨다.
- maxMs: 최대 듣는 시간(관리자 설정 qa.max_record_seconds)
- silenceStopMs: 이만큼 조용하면 말이 끝난 것으로 본다(qa.silence_stop_ms)

실패할 때는 { code, message } 객체로 reject 한다. 화면 동작은 다음과 같다.
- no_speech: "목소리가 들리지 않았어요" 안내 후 이야기 계속
- denied: 마이크 권한 안내
- cancelled: 조용히 이야기 계속
- unavailable, 그 밖: message 를 안내하고 이야기 계속

선택: 듣는 동안 소리 크기를 막대로 보여 주려면 `window.dispatchEvent(new CustomEvent('rememvr:level', { detail: { db: -35, speech: true } }))` 를 100ms 정도 간격으로 보낸다.


3. 5초 규칙(화면이 맡는다)

1. 아이가 말을 마치면(startListening 이 글로 끝나면) 바로 기다림 소리(qa.thinking_lines 의 가족 목소리 음성)를 들려주고 질문 문장을 서버로 보낸다.
2. 서버는 qa.timeout_ms(기본 5000) − 0.5초 안에 답한다. 답변 AI 와 음성 합성이 그 안에 끝나지 않으면 mode `timeout` 과 준비된 말(qa.timeout_lines)을 돌려주고 질문 횟수는 빼지 않는다.
3. 화면은 qa.timeout_ms 가 지나도 응답이 없으면 요청을 끊고 준비된 말을 직접 들려준다.

준비된 말의 가족 목소리 음성은 목소리마다 미리 만들어 둔다(관리자 운영 설정 → 모든 목소리 대체 음성 다시 만들기). 아직 없으면 기기 음성으로 읽는다.


4. 개발 모드

서버 config 의 `providers_fake` 가 true 면 연결 객체가 없어도 질문 버튼이 보이고, 질문 창에 글 입력칸이 나온다. 앱 없이 같은 흐름(기다림 소리, 5초 규칙, 질문 한도)을 점검할 수 있다. `qa.fake_delay_ms` 설정값(관리자 화면에는 없음)으로 답이 늦는 경우를 흉내 낼 수 있다.


5. 안드로이드 앱(android/)

- 웹뷰로 사이트(SITE_URL Secret, 없으면 https://rememvr.mycafe24.com)를 연다. 사용자 에이전트 끝에 RememvrApp/버전 을 붙인다.
- SpeechBridge.kt 가 Android 12 이상에서 기기 안 인식(createOnDeviceSpeechRecognizer)을 쓸 수 있을 때만 window.RememvrNative 를 넣는다. 그 밖의 폰과 일반 브라우저에서는 질문 버튼이 보이지 않는다.
- 웹과 앱의 통신은 WebMessageListener(RememvrAndroid) 로 하고, 우리 사이트 주소에서만 열린다.
- 한국어 기기 안 인식 모델이 없으면 Android 13 이상에서 내려받기를 요청하고, 이번 질문은 "준비하고 있어요" 안내로 끝낸다.
- 목소리 녹음(웹 getUserMedia)과 파일 올리기도 앱에서 쓸 수 있게 마이크 권한, 파일 선택을 연결했다.
- 빌드: GitHub Actions 의 Android APK 워크플로. main 에 반영되면 Releases 의 android-latest 에 rememvr.apk 를 올린다. 테스트 서명 키(android/app/rememvr-test.keystore)로 서명하므로 같은 키로 만든 새 APK 를 덮어 설치할 수 있다. Play 스토어에 올릴 때는 별도 업로드 키로 바꾼다.
- 알려진 제한: 구글 로그인은 웹뷰 안에서 막혀 있다(구글 정책). 앱에서는 이메일 또는 카카오 로그인을 쓴다. iOS 앱은 아직 없다.
