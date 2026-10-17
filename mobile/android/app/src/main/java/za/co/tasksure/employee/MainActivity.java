package za.co.tasksure.employee;

import com.getcapacitor.BridgeActivity;
import android.os.Bundle;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(PolicyDocumentsPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
