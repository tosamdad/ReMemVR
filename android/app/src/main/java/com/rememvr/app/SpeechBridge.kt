package com.rememvr.app

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import android.webkit.WebView
import androidx.webkit.JavaScriptReplyProxy
import androidx.webkit.WebMessageCompat
import androidx.webkit.WebViewCompat
import org.json.JSONObject

/**
 * 아이 질문용 폰 안 음성 인식을 웹 화면의 window.RememvrNative 로 연결한다(docs/native-bridge.md).
 *
 * - 아이 음성은 휴대폰 밖으로 보내지 않는다. 기기 안 인식(Android 12 이상 createOnDeviceSpeechRecognizer)만 쓰고,
 *   지원하지 않는 폰에서는 연결 객체를 넣지 않는다(웹 화면의 질문 버튼이 보이지 않는다).
 * - 웹과의 통신은 우리 사이트 주소에서만 열리는 WebMessageListener 로 한다(다른 사이트는 연결 객체를 볼 수 없다).
 */
class SpeechBridge(
    private val activity: MainActivity,
    private val webView: WebView,
    private val origin: String,
) {
    private val main = Handler(Looper.getMainLooper())
    private var recognizer: SpeechRecognizer? = null
    private var reply: JavaScriptReplyProxy? = null
    private var listening = false
    private var lastPartial = ""
    private var pendingOptions: JSONObject? = null
    private var installedAtStart = false
    private val maxTimer = Runnable { stop() }

    /** 기기 안 음성 인식을 쓸 수 있는지 */
    fun available(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S) return false
        if (!MainActivity.messageListenerSupported()) return false
        return SpeechRecognizer.isOnDeviceRecognitionAvailable(activity)
    }

    /** 연결 통로와(가능하면) 문서 시작 스크립트를 등록한다. */
    fun install() {
        if (!available()) return
        WebViewCompat.addWebMessageListener(webView, CHANNEL, setOf(origin)) { _, message, _, isMainFrame, replyProxy ->
            if (isMainFrame) onMessage(message, replyProxy)
        }
        if (MainActivity.documentStartSupported()) {
            WebViewCompat.addDocumentStartJavaScript(webView, SHIM, setOf(origin))
            installedAtStart = true
        }
    }

    /** 문서 시작 스크립트를 못 쓰는 웹뷰: 페이지가 뜬 뒤 넣고 rememvr:native-ready 로 알린다. */
    fun injectIfNeeded(url: String) {
        if (installedAtStart || !available()) return
        val u = Uri.parse(url)
        val pageOrigin = "${u.scheme}://${u.host}" + (if (u.port > 0) ":${u.port}" else "")
        if (pageOrigin == origin) webView.evaluateJavascript(SHIM, null)
    }

    private fun onMessage(message: WebMessageCompat, replyProxy: JavaScriptReplyProxy) {
        reply = replyProxy
        val data = try {
            JSONObject(message.data ?: "{}")
        } catch (e: Exception) {
            return
        }
        when (data.optString("type")) {
            "start" -> start(data.optJSONObject("options") ?: JSONObject())
            "stop" -> stop()
            "cancel" -> cancel()
        }
    }

    private fun start(options: JSONObject) {
        // 웹 쪽은 이전 요청을 이미 끝냈으므로 알리지 않고 정리한다(새 요청을 잘못 끝내지 않게).
        cancel(notify = false)
        if (!activity.hasMicPermission()) {
            pendingOptions = options
            activity.requestMicForSpeech()
            return
        }
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.S || !SpeechRecognizer.isOnDeviceRecognitionAvailable(activity)) {
            fail("unavailable", "이 휴대폰에서는 말로 묻기를 쓸 수 없어요.")
            return
        }
        val r = SpeechRecognizer.createOnDeviceSpeechRecognizer(activity)
        recognizer = r
        listening = true
        lastPartial = ""
        r.setRecognitionListener(listener)
        val silence = options.optLong("silenceStopMs", 1200L).coerceIn(500L, 5000L)
        val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).apply {
            putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            putExtra(RecognizerIntent.EXTRA_LANGUAGE, options.optString("lang", "ko-KR"))
            putExtra(RecognizerIntent.EXTRA_PARTIAL_RESULTS, true)
            putExtra(RecognizerIntent.EXTRA_PREFER_OFFLINE, true)
            putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 1)
            putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_COMPLETE_SILENCE_LENGTH_MILLIS, silence)
            putExtra(RecognizerIntent.EXTRA_SPEECH_INPUT_POSSIBLY_COMPLETE_SILENCE_LENGTH_MILLIS, silence)
        }
        r.startListening(intent)
        val maxMs = options.optLong("maxMs", 15000L).coerceIn(3000L, 60000L)
        main.postDelayed(maxTimer, maxMs)
    }

    fun onMicPermissionResult(granted: Boolean) {
        val options = pendingOptions ?: return
        pendingOptions = null
        if (granted) start(options) else fail("denied", "마이크 권한을 허용해 주세요.")
    }

    /** 아이가 마이크를 다시 눌렀다: 지금까지 들은 말로 끝낸다. */
    fun stop() {
        main.removeCallbacks(maxTimer)
        if (listening) recognizer?.stopListening()
    }

    /** 질문 창을 닫았거나 앱을 내렸다: 인식을 버린다. */
    fun cancel(notify: Boolean = true) {
        main.removeCallbacks(maxTimer)
        pendingOptions = null
        if (listening) {
            listening = false
            if (notify) send(JSONObject().put("type", "error").put("code", "cancelled"))
        }
        recognizer?.cancel()
        recognizer?.destroy()
        recognizer = null
    }

    fun destroy() {
        cancel()
        reply = null
    }

    private fun finish(text: String) {
        main.removeCallbacks(maxTimer)
        if (!listening) return
        listening = false
        val t = text.trim()
        if (t.isEmpty()) {
            send(JSONObject().put("type", "error").put("code", "no_speech"))
        } else {
            send(JSONObject().put("type", "result").put("text", t))
        }
        releaseLater()
    }

    private fun fail(code: String, message: String) {
        main.removeCallbacks(maxTimer)
        listening = false
        send(JSONObject().put("type", "error").put("code", code).put("message", message))
        releaseLater()
    }

    private fun releaseLater() {
        val r = recognizer
        recognizer = null
        main.post { r?.destroy() }
    }

    private fun send(obj: JSONObject) {
        main.post { reply?.postMessage(obj.toString()) }
    }

    private val listener = object : RecognitionListener {
        override fun onReadyForSpeech(params: Bundle?) {}
        override fun onBeginningOfSpeech() {}

        override fun onRmsChanged(rmsdB: Float) {
            if (!listening) return
            // 인식기의 소리 크기(대략 -2~10)를 웹 막대용 dB(-60~-10)로 바꾼다.
            val db = (-60f + (rmsdB + 2f) * 4.2f).coerceIn(-60f, -5f)
            send(JSONObject().put("type", "level").put("db", db.toDouble()).put("speech", rmsdB > 3f))
        }

        override fun onBufferReceived(buffer: ByteArray?) {}
        override fun onEndOfSpeech() {}

        override fun onError(error: Int) {
            if (!listening) return
            when (error) {
                SpeechRecognizer.ERROR_NO_MATCH, SpeechRecognizer.ERROR_SPEECH_TIMEOUT ->
                    if (lastPartial.isNotBlank()) finish(lastPartial) else fail("no_speech", "목소리가 들리지 않았어요.")
                SpeechRecognizer.ERROR_INSUFFICIENT_PERMISSIONS -> fail("denied", "마이크 권한을 허용해 주세요.")
                SpeechRecognizer.ERROR_LANGUAGE_NOT_SUPPORTED, SpeechRecognizer.ERROR_LANGUAGE_UNAVAILABLE -> {
                    // 한국어 기기 안 인식 모델이 없으면 내려받기를 요청한다(Android 13 이상).
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                        try {
                            recognizer?.triggerModelDownload(Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH).putExtra(RecognizerIntent.EXTRA_LANGUAGE, "ko-KR"))
                        } catch (e: Exception) {
                            // 내려받기를 지원하지 않는 인식기
                        }
                    }
                    fail("unavailable", "한국어 음성 인식을 준비하고 있어요. 잠시 뒤 다시 물어봐요.")
                }
                else -> fail("unavailable", "말을 알아듣지 못했어요. 다시 물어봐요.")
            }
        }

        override fun onResults(results: Bundle?) {
            val list = results?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)
            finish(list?.firstOrNull() ?: lastPartial)
        }

        override fun onPartialResults(partialResults: Bundle?) {
            val list = partialResults?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)
            val t = list?.firstOrNull()
            if (!t.isNullOrBlank()) lastPartial = t
        }

        override fun onEvent(eventType: Int, params: Bundle?) {}
    }

    companion object {
        private const val CHANNEL = "RememvrAndroid"

        /** 웹 화면에 넣는 연결 객체(docs/native-bridge.md 의 window.RememvrNative). */
        private val SHIM = """
            (function () {
              if (window.RememvrNative || !window.$CHANNEL) return;
              var ch = window.$CHANNEL, pending = null;
              ch.onmessage = function (e) {
                var m;
                try { m = JSON.parse(e.data); } catch (x) { return; }
                if (m.type === 'level') {
                  window.dispatchEvent(new CustomEvent('rememvr:level', { detail: { db: m.db, speech: !!m.speech } }));
                  return;
                }
                if (!pending) return;
                var p = pending; pending = null;
                if (m.type === 'result') p.resolve({ text: m.text });
                else p.reject({ code: m.code || 'unavailable', message: m.message || '' });
              };
              window.RememvrNative = {
                platform: 'android',
                startListening: function (options) {
                  if (pending) { var old = pending; pending = null; old.reject({ code: 'cancelled' }); }
                  return new Promise(function (resolve, reject) {
                    pending = { resolve: resolve, reject: reject };
                    ch.postMessage(JSON.stringify({ type: 'start', options: options || {} }));
                  });
                },
                stopListening: function () { ch.postMessage(JSON.stringify({ type: 'stop' })); },
                cancelListening: function () {
                  ch.postMessage(JSON.stringify({ type: 'cancel' }));
                  if (pending) { var p = pending; pending = null; p.reject({ code: 'cancelled' }); }
                }
              };
              var ready = function () { window.dispatchEvent(new Event('rememvr:native-ready')); };
              if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
            })();
        """.trimIndent()
    }
}
