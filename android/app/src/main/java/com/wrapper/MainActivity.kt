package {{PACKAGE_NAME}}

import android.Manifest
import android.annotation.SuppressLint
import android.app.DownloadManager
import android.content.ActivityNotFoundException
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.PermissionRequest
import android.webkit.RenderProcessGoneDetail
import android.webkit.URLUtil
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.TextView
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import androidx.core.view.updatePadding
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout
import androidx.webkit.WebViewAssetLoader
import java.net.URISyntaxException
import java.util.Locale

/**
 * Generischer WebView-Wrapper.
 *
 * Konfiguration kommt aus Ressourcen (von Generator.php befuellt und dort
 * maskiert): R.string.wrapper_url, R.string.wrapper_display_mode,
 * R.color.theme_color.
 *
 * Sicherheitsmodell:
 *  - Nur die eigene Domain (inkl. Subdomains) wird in der App geladen,
 *    fremde Seiten oeffnen sich im Standard-Browser (Anti-Phishing: die
 *    App zeigt keine fremden Seiten unter eigenem Namen).
 *  - Nur https; kein Dateizugriff, kein Content-Provider-Zugriff aus der
 *    Webseite, kein JavaScript-Bridge-Objekt (addJavascriptInterface).
 *  - SSL-Fehler werden NICHT uebergangen (Standardverhalten: Abbruch).
 *  - Kamera wird nur der eigenen Domain und nur nach Rueckfrage gewaehrt.
 *  - intent://-Links werden entschaerft (keine expliziten Komponenten).
 *  - Keine Backups der Cookies/Sitzungen (siehe AndroidManifest).
 */
class MainActivity : AppCompatActivity() {

    private lateinit var rootView: FrameLayout
    private lateinit var webView: WebView
    private lateinit var swipeRefresh: SwipeRefreshLayout
    private lateinit var offlineView: TextView

    private val startUrl: String by lazy { getString(R.string.wrapper_url) }
    private val trustedHost: String by lazy {
        (Uri.parse(startUrl).host ?: "").lowercase(Locale.ROOT).removePrefix("www.")
    }
    private val immersive: Boolean by lazy { getString(R.string.wrapper_display_mode) == "immersive" }
    private val themeColor: Int by lazy { ContextCompat.getColor(this, R.color.theme_color) }

    /**
     * Variante 2: Die Webseite steckt als Dateien in der App (assets/www).
     * Sie wird ueber WebViewAssetLoader unter
     * https://appassets.androidplatform.net/assets/... ausgeliefert - also
     * ueber eine echte https-Adresse statt file://, damit fetch(), Module,
     * localStorage usw. normal funktionieren und allowFileAccess aus bleiben kann.
     */
    private val localMode: Boolean by lazy { getString(R.string.wrapper_source) == "local" }
    private lateinit var assetLoader: WebViewAssetLoader

    private var fileUploadCallback: ValueCallback<Array<Uri>>? = null
    private var pendingWebPermission: PermissionRequest? = null
    private var pendingDownload: PendingDownload? = null

    private data class PendingDownload(
        val url: String,
        val userAgent: String?,
        val contentDisposition: String?,
        val mimeType: String?
    )

    private val fileChooserLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        val data = result.data
        val uris: Array<Uri>? = if (result.resultCode == RESULT_OK && data != null) {
            val clip = data.clipData
            val single = data.data
            when {
                clip != null -> Array(clip.itemCount) { i -> clip.getItemAt(i).uri }
                single != null -> arrayOf(single)
                else -> null
            }
        } else {
            null
        }
        fileUploadCallback?.onReceiveValue(uris)
        fileUploadCallback = null
    }

    private val cameraPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        val request = pendingWebPermission ?: return@registerForActivityResult
        pendingWebPermission = null
        if (granted) {
            request.grant(arrayOf(PermissionRequest.RESOURCE_VIDEO_CAPTURE))
        } else {
            request.deny()
        }
    }

    private val storagePermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        val download = pendingDownload ?: return@registerForActivityResult
        pendingDownload = null
        if (granted) enqueueDownload(download) else toast(R.string.download_failed)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)

        // Remote-Debugging des WebView-Inhalts in der Release-App aus.
        WebView.setWebContentsDebuggingEnabled(false)

        setContentView(buildLayout())
        applyDisplayMode()
        applyInsets()
        configureWebView()
        setupBackNavigation()

        // Mitgelieferte Dateien (Variante 2) brauchen kein Internet.
        if (localMode || isNetworkAvailable()) {
            webView.loadUrl(startUrl)
        } else {
            showOffline()
        }
    }

    // -----------------------------------------------------------------
    // Layout
    // -----------------------------------------------------------------
    private fun buildLayout(): View {
        rootView = FrameLayout(this).apply { setBackgroundColor(themeColor) }

        webView = WebView(this)
        swipeRefresh = SwipeRefreshLayout(this).apply {
            addView(webView, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            setOnRefreshListener { webView.reload() }
            setColorSchemeColors(themeColor)
            setOnChildScrollUpCallback { _, _ -> webView.scrollY > 0 }
        }
        rootView.addView(swipeRefresh, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)

        offlineView = TextView(this).apply {
            text = getString(R.string.offline_message)
            textSize = 16f
            gravity = Gravity.CENTER
            setTextColor(if (isColorLight(themeColor)) Color.BLACK else Color.WHITE)
            setPadding(64, 64, 64, 64)
            visibility = View.GONE
            setOnClickListener { retry() }
        }
        rootView.addView(offlineView, ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)

        return rootView
    }

    // -----------------------------------------------------------------
    // Anzeigemodus: "immersive" = echtes Vollbild, "standalone" = sichtbare
    // Statusleiste in der Theme-Farbe.
    // -----------------------------------------------------------------
    private fun applyDisplayMode() {
        WindowCompat.setDecorFitsSystemWindows(window, false)
        val controller = WindowInsetsControllerCompat(window, window.decorView)

        if (immersive) {
            controller.systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
            controller.hide(WindowInsetsCompat.Type.systemBars())
        } else {
            controller.show(WindowInsetsCompat.Type.systemBars())
            @Suppress("DEPRECATION")
            window.statusBarColor = themeColor
            controller.isAppearanceLightStatusBars = isColorLight(themeColor)
        }
    }

    /**
     * Da die App randlos zeichnet, werden Statusleiste, Navigationsleiste,
     * Kamera-Aussparung und die Bildschirmtastatur als Innenabstand
     * beruecksichtigt. Ohne das laege die Webseite im Standalone-Modus unter
     * der Statusleiste und Eingabefelder unter der Tastatur.
     */
    private fun applyInsets() {
        ViewCompat.setOnApplyWindowInsetsListener(rootView) { view, insets ->
            val ime = insets.getInsets(WindowInsetsCompat.Type.ime())
            if (immersive) {
                view.updatePadding(left = 0, top = 0, right = 0, bottom = ime.bottom)
            } else {
                val bars = insets.getInsets(
                    WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
                )
                view.updatePadding(
                    left = bars.left,
                    top = bars.top,
                    right = bars.right,
                    bottom = maxOf(bars.bottom, ime.bottom)
                )
            }
            WindowInsetsCompat.CONSUMED
        }
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus && immersive) applyDisplayMode()
    }

    // -----------------------------------------------------------------
    // WebView
    // -----------------------------------------------------------------
    @SuppressLint("SetJavaScriptEnabled")
    private fun configureWebView() {
        assetLoader = WebViewAssetLoader.Builder()
            .addPathHandler("/assets/", WebViewAssetLoader.AssetsPathHandler(applicationContext))
            .build()

        webView.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            allowFileAccess = false
            allowContentAccess = false
            setGeolocationEnabled(false)
            loadWithOverviewMode = true
            useWideViewPort = true
            builtInZoomControls = true
            displayZoomControls = false
            mediaPlaybackRequiresUserGesture = true
            mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
            cacheMode = WebSettings.LOAD_DEFAULT
            setSupportMultipleWindows(false)
            javaScriptCanOpenWindowsAutomatically = false
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                safeBrowsingEnabled = true
            }
        }

        CookieManager.getInstance().apply {
            setAcceptCookie(true)
            setAcceptThirdPartyCookies(webView, false)
        }

        webView.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean =
                handleNavigation(request.url, request.isForMainFrame)

            // Variante 2: Anfragen an https://appassets.androidplatform.net/assets/...
            // aus dem App-Paket beantworten. Alles andere geht normal ins Netz.
            override fun shouldInterceptRequest(view: WebView, request: WebResourceRequest): WebResourceResponse? =
                if (localMode) assetLoader.shouldInterceptRequest(request.url) else null

            override fun onPageFinished(view: WebView, url: String?) {
                swipeRefresh.isRefreshing = false
            }

            override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
                if (request.isForMainFrame) showOffline()
            }

            // onReceivedSslError wird bewusst NICHT ueberschrieben: das
            // Standardverhalten bricht bei Zertifikatsfehlern ab.

            override fun onRenderProcessGone(view: WebView, detail: RenderProcessGoneDetail): Boolean {
                // Absturz des Web-Renderers: App nicht mitreissen, sondern neu aufbauen.
                recreate()
                return true
            }
        }

        webView.webChromeClient = object : WebChromeClient() {
            override fun onShowFileChooser(
                view: WebView,
                callback: ValueCallback<Array<Uri>>,
                params: FileChooserParams
            ): Boolean {
                fileUploadCallback?.onReceiveValue(null)
                fileUploadCallback = callback
                return try {
                    fileChooserLauncher.launch(params.createIntent())
                    true
                } catch (e: ActivityNotFoundException) {
                    fileUploadCallback = null
                    false
                }
            }

            override fun onPermissionRequest(request: PermissionRequest) {
                runOnUiThread { handleWebPermission(request) }
            }

            override fun onPermissionRequestCanceled(request: PermissionRequest) {
                if (pendingWebPermission === request) pendingWebPermission = null
            }
        }

        webView.setDownloadListener { url, userAgent, contentDisposition, mimeType, _ ->
            val download = PendingDownload(url, userAgent, contentDisposition, mimeType)
            val needsLegacyPermission = Build.VERSION.SDK_INT < Build.VERSION_CODES.Q &&
                ContextCompat.checkSelfPermission(
                    this@MainActivity,
                    Manifest.permission.WRITE_EXTERNAL_STORAGE
                ) != PackageManager.PERMISSION_GRANTED

            if (needsLegacyPermission) {
                pendingDownload = download
                storagePermissionLauncher.launch(Manifest.permission.WRITE_EXTERNAL_STORAGE)
            } else {
                enqueueDownload(download)
            }
        }
    }

    // -----------------------------------------------------------------
    // Navigation
    // -----------------------------------------------------------------

    /** @return true = WebView soll die URL NICHT selbst laden. */
    private fun handleNavigation(uri: Uri, isMainFrame: Boolean): Boolean {
        val scheme = uri.scheme?.lowercase(Locale.ROOT) ?: return true
        return when {
            // Eigene Domain -> in der App. Eingebettete https-Frames (z.B.
            // Videos, Karten) duerfen ebenfalls laden.
            scheme == "https" && (isTrustedHost(uri.host) || !isMainFrame) -> false
            // Fremde Seiten -> Standard-Browser.
            scheme == "https" || scheme == "http" -> {
                openExternally(uri)
                true
            }
            scheme == "intent" -> {
                openIntentUri(uri.toString())
                true
            }
            scheme in EXTERNAL_SCHEMES -> {
                openExternally(uri)
                true
            }
            // file:, content:, javascript:, data:, ... -> blockiert.
            else -> true
        }
    }

    private fun isTrustedHost(host: String?): Boolean {
        if (host.isNullOrEmpty() || trustedHost.isEmpty()) return false
        val h = host.lowercase(Locale.ROOT)
        return h == trustedHost || h.endsWith(".$trustedHost")
    }

    private fun openExternally(uri: Uri) {
        try {
            startActivity(Intent(Intent.ACTION_VIEW, uri))
        } catch (e: ActivityNotFoundException) {
            toast(R.string.no_app_found)
        }
    }

    /**
     * intent://-Links entschaerfen: Eine Webseite darf darueber keine
     * gezielten (auch nicht exportierten) Komponenten starten und keine
     * URI-Berechtigungen vergeben.
     */
    private fun openIntentUri(raw: String) {
        val intent = try {
            Intent.parseUri(raw, Intent.URI_INTENT_SCHEME)
        } catch (e: URISyntaxException) {
            return
        }
        val fallback = intent.getStringExtra("browser_fallback_url")

        intent.addCategory(Intent.CATEGORY_BROWSABLE)
        intent.component = null
        intent.selector = null
        intent.removeFlags(
            Intent.FLAG_GRANT_READ_URI_PERMISSION or
                Intent.FLAG_GRANT_WRITE_URI_PERMISSION or
                Intent.FLAG_GRANT_PERSISTABLE_URI_PERMISSION or
                Intent.FLAG_GRANT_PREFIX_URI_PERMISSION
        )

        try {
            startActivity(intent)
        } catch (e: ActivityNotFoundException) {
            if (fallback != null) {
                val fallbackUri = Uri.parse(fallback)
                if ("https".equals(fallbackUri.scheme, ignoreCase = true)) {
                    if (isTrustedHost(fallbackUri.host)) webView.loadUrl(fallback) else openExternally(fallbackUri)
                }
            }
        }
    }

    private fun setupBackNavigation() {
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                if (webView.canGoBack()) {
                    webView.goBack()
                } else {
                    isEnabled = false
                    onBackPressedDispatcher.onBackPressed()
                }
            }
        })
    }

    // -----------------------------------------------------------------
    // Berechtigungen der Webseite (Kamera fuer WebRTC)
    // -----------------------------------------------------------------
    private fun handleWebPermission(request: PermissionRequest) {
        val origin = request.origin
        val wantsCamera = request.resources.contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE)

        if (!"https".equals(origin.scheme, ignoreCase = true) || !isTrustedHost(origin.host) || !wantsCamera) {
            request.deny()
            return
        }

        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
            request.grant(arrayOf(PermissionRequest.RESOURCE_VIDEO_CAPTURE))
        } else {
            pendingWebPermission?.deny()
            pendingWebPermission = request
            cameraPermissionLauncher.launch(Manifest.permission.CAMERA)
        }
    }

    // -----------------------------------------------------------------
    // Downloads (nur https; Cookies werden fuer eingeloggte Downloads
    // mitgegeben - CookieManager liefert nur die Cookies DIESER URL)
    // -----------------------------------------------------------------
    private fun enqueueDownload(download: PendingDownload) {
        val uri = Uri.parse(download.url)
        if (!"https".equals(uri.scheme, ignoreCase = true)) {
            toast(R.string.download_failed)
            return
        }

        try {
            val fileName = URLUtil.guessFileName(download.url, download.contentDisposition, download.mimeType)
                .replace('/', '_')
                .replace('\\', '_')

            val request = DownloadManager.Request(uri).apply {
                if (!download.mimeType.isNullOrBlank()) setMimeType(download.mimeType)
                download.userAgent?.let { addRequestHeader("User-Agent", it) }
                CookieManager.getInstance().getCookie(download.url)?.let { addRequestHeader("Cookie", it) }
                setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName)
            }
            (getSystemService(DOWNLOAD_SERVICE) as DownloadManager).enqueue(request)
            toast(R.string.download_started)
        } catch (e: Exception) {
            toast(R.string.download_failed)
        }
    }

    // -----------------------------------------------------------------
    // Konnektivitaet / Offline
    // -----------------------------------------------------------------
    private fun isNetworkAvailable(): Boolean {
        val cm = getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager
        val network = cm.activeNetwork ?: return false
        val capabilities = cm.getNetworkCapabilities(network) ?: return false
        return capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
    }

    private fun showOffline() {
        swipeRefresh.isRefreshing = false
        swipeRefresh.visibility = View.GONE
        offlineView.visibility = View.VISIBLE
    }

    private fun retry() {
        if (!localMode && !isNetworkAvailable()) return
        offlineView.visibility = View.GONE
        swipeRefresh.visibility = View.VISIBLE
        val current = webView.url
        if (current.isNullOrEmpty() || current == "about:blank") webView.loadUrl(startUrl) else webView.reload()
    }

    // -----------------------------------------------------------------
    // Hilfsfunktionen / Lebenszyklus
    // -----------------------------------------------------------------
    private fun isColorLight(color: Int): Boolean {
        val luminance = (0.299 * Color.red(color) + 0.587 * Color.green(color) + 0.114 * Color.blue(color)) / 255
        return luminance > 0.5
    }

    private fun toast(resId: Int) {
        Toast.makeText(this, resId, Toast.LENGTH_SHORT).show()
    }

    override fun onResume() {
        super.onResume()
        webView.onResume()
    }

    override fun onPause() {
        webView.onPause()
        super.onPause()
    }

    override fun onDestroy() {
        fileUploadCallback?.onReceiveValue(null)
        fileUploadCallback = null
        pendingWebPermission?.deny()
        pendingWebPermission = null
        webView.stopLoading()
        swipeRefresh.removeView(webView)
        webView.destroy()
        super.onDestroy()
    }

    private companion object {
        val EXTERNAL_SCHEMES = setOf("tel", "mailto", "sms", "smsto", "geo", "market")
    }
}
