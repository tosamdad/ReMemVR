package com.rememvr.app

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.PermissionRequest
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Toast
import androidx.webkit.WebViewFeature

/**
 * 르멤버 하이브리드 앱: 사이트를 웹뷰로 열고, 아이 질문용 폰 안 음성 인식(SpeechBridge)을 웹 화면에 연결한다.
 * 연결 규약은 저장소 docs/native-bridge.md 를 따른다.
 */
class MainActivity : Activity() {

    private lateinit var webView: WebView
    private lateinit var speech: SpeechBridge
    private var fileCallback: ValueCallback<Array<Uri>>? = null
    private var pendingWebPermission: PermissionRequest? = null
    private val siteUri: Uri = Uri.parse(BuildConfig.SITE_URL)

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        webView = WebView(this)
        webView.layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
        setContentView(webView)

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            // 질문 답, 준비된 말을 버튼 누름 없이 이어서 재생해야 한다.
            mediaPlaybackRequiresUserGesture = false
            userAgentString = "$userAgentString RememvrApp/${BuildConfig.VERSION_NAME}"
        }
        CookieManager.getInstance().setAcceptCookie(true)
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true)

        speech = SpeechBridge(this, webView, siteOrigin())
        speech.install()

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                val uri = request.url
                val scheme = uri.scheme ?: return false
                if (scheme == "http" || scheme == "https") return false
                // tel:, mailto:, intent: 같은 주소는 다른 앱으로 연다.
                return try {
                    startActivity(Intent(Intent.ACTION_VIEW, uri))
                    true
                } catch (e: ActivityNotFoundException) {
                    true
                }
            }

            override fun onPageFinished(view: WebView, url: String) {
                super.onPageFinished(view, url)
                // 문서 시작 스크립트를 쓸 수 없는 웹뷰에서는 페이지가 뜬 뒤 연결 객체를 넣는다.
                speech.injectIfNeeded(url)
                CookieManager.getInstance().flush()
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            // 목소리 녹음(웹 getUserMedia): 우리 사이트의 마이크 요청만 허락한다.
            override fun onPermissionRequest(request: PermissionRequest) {
                runOnUiThread {
                    val ours = request.origin.host == siteUri.host
                    val wantsAudio = request.resources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE)
                    if (!ours || !wantsAudio) {
                        request.deny()
                        return@runOnUiThread
                    }
                    if (hasMicPermission()) {
                        request.grant(arrayOf(PermissionRequest.RESOURCE_AUDIO_CAPTURE))
                    } else {
                        pendingWebPermission = request
                        requestPermissions(arrayOf(Manifest.permission.RECORD_AUDIO), REQ_MIC_WEB)
                    }
                }
            }

            // 파일 올리기(녹음 파일, 사진)
            override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = callback
                return try {
                    startActivityForResult(params.createIntent(), REQ_FILE)
                    true
                } catch (e: ActivityNotFoundException) {
                    fileCallback = null
                    false
                }
            }
        }

        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
        } else {
            webView.loadUrl(startUrl(intent))
        }
    }

    private fun siteOrigin(): String = "${siteUri.scheme}://${siteUri.host}" + (if (siteUri.port > 0) ":${siteUri.port}" else "")

    private fun startUrl(intent: Intent?): String {
        val data = intent?.data
        return if (data != null && data.host == siteUri.host) data.toString() else BuildConfig.SITE_URL
    }

    fun hasMicPermission(): Boolean =
        checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED

    fun requestMicForSpeech() {
        requestPermissions(arrayOf(Manifest.permission.RECORD_AUDIO), REQ_MIC_SPEECH)
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        val granted = grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED
        when (requestCode) {
            REQ_MIC_WEB -> {
                val req = pendingWebPermission
                pendingWebPermission = null
                if (req != null) {
                    if (granted) req.grant(arrayOf(PermissionRequest.RESOURCE_AUDIO_CAPTURE)) else req.deny()
                }
                if (!granted) Toast.makeText(this, "녹음하려면 마이크 권한을 허용해 주세요.", Toast.LENGTH_LONG).show()
            }
            REQ_MIC_SPEECH -> speech.onMicPermissionResult(granted)
        }
    }

    @Deprecated("Activity 결과 API 를 쓰지 않는 단순한 앱이라 이 방식을 쓴다.")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode == REQ_FILE) {
            fileCallback?.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(resultCode, data))
            fileCallback = null
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        val data = intent.data
        if (data != null && data.host == siteUri.host) webView.loadUrl(data.toString())
    }

    @Deprecated("뒤로 가기를 웹 기록으로 처리한다.")
    override fun onBackPressed() {
        if (webView.canGoBack()) webView.goBack() else super.onBackPressed()
    }

    override fun onPause() {
        super.onPause()
        // 앱을 내리면 듣고 있던 마이크를 끈다.
        speech.cancel()
        CookieManager.getInstance().flush()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView.saveState(outState)
    }

    override fun onDestroy() {
        speech.destroy()
        webView.destroy()
        super.onDestroy()
    }

    companion object {
        private const val REQ_MIC_WEB = 1
        private const val REQ_MIC_SPEECH = 2
        private const val REQ_FILE = 3

        fun documentStartSupported(): Boolean =
            WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)

        fun messageListenerSupported(): Boolean =
            WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)
    }
}
