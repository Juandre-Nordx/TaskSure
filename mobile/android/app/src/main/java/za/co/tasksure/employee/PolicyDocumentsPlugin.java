package za.co.tasksure.employee;

import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import androidx.activity.result.ActivityResult;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.ActivityCallback;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.io.File;
import java.io.FileInputStream;
import java.io.InputStream;
import java.io.OutputStream;

/** Android's Save As picker exports an authenticated, app-private PDF copy.
 * No public server links, bearer tokens or broad storage permissions are needed.
 */
@CapacitorPlugin(name = "PolicyDocuments")
public class PolicyDocumentsPlugin extends Plugin {
    @PluginMethod
    public void savePdf(PluginCall call) {
        try {
            source(call);
            String name = call.getString("name", "company-policy.pdf");
            if (!name.matches("company-policy-v[0-9]+\\.pdf")) name = "company-policy.pdf";
            Intent intent = new Intent(Intent.ACTION_CREATE_DOCUMENT);
            intent.addCategory(Intent.CATEGORY_OPENABLE);
            intent.setType("application/pdf");
            intent.putExtra(Intent.EXTRA_TITLE, name);
            startActivityForResult(call, intent, "saveResult");
        } catch (Exception exception) {
            call.reject("Could not open Save PDF. Please try again.", exception);
        }
    }

    private File source(PluginCall call) throws Exception {
        String path = call.getString("path");
        if (path == null) throw new IllegalArgumentException("Missing PDF");
        Uri uri = Uri.parse(path);
        if (!"file".equals(uri.getScheme()) || uri.getPath() == null) throw new IllegalArgumentException("Invalid PDF path");
        File file = new File(uri.getPath()).getCanonicalFile();
        File cache = new File(getContext().getCacheDir(), "tasksure-evidence").getCanonicalFile();
        if (!file.getPath().startsWith(cache.getPath() + File.separator) || !file.isFile() || !file.getName().endsWith(".pdf")) {
            throw new IllegalArgumentException("PDF must be in the private document cache");
        }
        return file;
    }

    @ActivityCallback
    private void saveResult(PluginCall call, ActivityResult result) {
        if (call == null) return;
        if (result.getResultCode() != Activity.RESULT_OK || result.getData() == null || result.getData().getData() == null) {
            call.resolve(new JSObject().put("saved", false));
            return;
        }
        Uri destination = result.getData().getData();
        getBridge().execute(() -> {
            try {
                try (InputStream input = new FileInputStream(source(call));
                     OutputStream output = getContext().getContentResolver().openOutputStream(destination, "w")) {
                    if (output == null) throw new IllegalStateException("No writable document selected");
                    byte[] buffer = new byte[16384];
                    int count;
                    while ((count = input.read(buffer)) != -1) output.write(buffer, 0, count);
                    output.flush();
                }
                call.resolve(new JSObject().put("saved", true));
            } catch (Exception exception) {
                call.reject("The PDF could not be saved. Check the selected location and try again.", exception);
            }
        });
    }
}
