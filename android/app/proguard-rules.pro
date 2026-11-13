# WebView-Wrapper: keine app-spezifische Logik, daher genuegen die
# Standard-Optimierungen von Android. Diese Datei existiert bewusst
# fast leer, damit build.gradle auf sie verweisen kann.

-keepattributes JavascriptInterface
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}
