package org.dualdb.admin;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.view.Gravity;
import android.view.View;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import java.io.File;
import java.net.URI;

public final class MainActivity extends Activity {
    private static final String PREFS = "dualdb_admin";
    private static final String URL_KEY = "server_url";
    private static final String DEFAULT_URL = "http://localhost:3232";
    private WebView webView;
    private TextView status;

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        File documents = getExternalFilesDir(Environment.DIRECTORY_DOCUMENTS);
        if (documents != null) new File(documents, "DualDB Admin").mkdirs();
        setContentView(buildUi());
        configureWebView();
        loadConfiguredUrl();
    }

    private View buildUi() {
        LinearLayout root = new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL); root.setBackgroundColor(Color.rgb(9, 16, 24));
        LinearLayout toolbar = new LinearLayout(this); toolbar.setGravity(Gravity.CENTER_VERTICAL); toolbar.setPadding(8, 8, 8, 8);
        Button reload = button("Reload"); reload.setOnClickListener(view -> webView.reload());
        Button server = button("Server"); server.setOnClickListener(view -> promptServer());
        Button browser = button("Browser"); browser.setOnClickListener(view -> startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(currentUrl()))));
        status = new TextView(this); status.setTextColor(Color.WHITE); status.setSingleLine(true); status.setPadding(12, 0, 0, 0);
        toolbar.addView(reload); toolbar.addView(server); toolbar.addView(browser); toolbar.addView(status, new LinearLayout.LayoutParams(0, -2, 1));
        webView = new WebView(this);
        root.addView(toolbar, new LinearLayout.LayoutParams(-1, -2)); root.addView(webView, new LinearLayout.LayoutParams(-1, 0, 1));
        return root;
    }

    private Button button(String text) { Button value = new Button(this); value.setText(text); return value; }

    private void configureWebView() {
        webView.getSettings().setJavaScriptEnabled(true);
        webView.getSettings().setDomStorageEnabled(true);
        webView.getSettings().setAllowFileAccess(false);
        webView.getSettings().setAllowContentAccess(false);
        if (Build.VERSION.SDK_INT >= 21) webView.getSettings().setMixedContentMode(android.webkit.WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        webView.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, String url) {
                if (sameOrigin(currentUrl(), url)) return false;
                startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))); return true;
            }
            @Override public void onPageFinished(WebView view, String url) { status.setText(Uri.parse(url).getHost()); }
        });
    }

    private boolean sameOrigin(String left, String right) {
        try {
            URI a = new URI(left), b = new URI(right);
            return a.getScheme().equalsIgnoreCase(b.getScheme()) && a.getHost().equalsIgnoreCase(b.getHost()) && normalizedPort(a) == normalizedPort(b);
        } catch (Exception ignored) { return false; }
    }

    private int normalizedPort(URI uri) { return uri.getPort() >= 0 ? uri.getPort() : ("https".equalsIgnoreCase(uri.getScheme()) ? 443 : 80); }
    private SharedPreferences preferences() { return getSharedPreferences(PREFS, MODE_PRIVATE); }
    private String currentUrl() { return preferences().getString(URL_KEY, DEFAULT_URL); }
    private void loadConfiguredUrl() { webView.loadUrl(currentUrl()); status.setText("Loading…"); }

    private void promptServer() {
        EditText input = new EditText(this); input.setSingleLine(true); input.setText(currentUrl()); input.setSelectAllOnFocus(true);
        new AlertDialog.Builder(this).setTitle("Admin server URL").setMessage("Use a trusted PHP server. Credentials in URLs are rejected.").setView(input)
            .setPositiveButton("Save", (dialog, which) -> {
                String candidate = input.getText().toString().trim();
                try {
                    URI uri = new URI(candidate);
                    if (!("http".equalsIgnoreCase(uri.getScheme()) || "https".equalsIgnoreCase(uri.getScheme())) || uri.getHost() == null || uri.getUserInfo() != null) throw new IllegalArgumentException();
                    preferences().edit().putString(URL_KEY, candidate).apply(); loadConfiguredUrl();
                } catch (Exception error) { Toast.makeText(this, "Enter an HTTP(S) URL without embedded credentials.", Toast.LENGTH_LONG).show(); }
            }).setNegativeButton("Cancel", null).show();
    }

    @Override public void onBackPressed() { if (webView.canGoBack()) webView.goBack(); else super.onBackPressed(); }
    @Override protected void onDestroy() { webView.destroy(); super.onDestroy(); }
}
