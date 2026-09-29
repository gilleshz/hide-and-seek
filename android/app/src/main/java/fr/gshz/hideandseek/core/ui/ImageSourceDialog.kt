package fr.gshz.hideandseek.core.ui

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.annotation.StringRes
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing
import java.io.File

@Composable
fun ImageSourceDialog(
    onCameraClick: () -> Unit,
    onGalleryClick: () -> Unit,
    onDismiss: () -> Unit,
    @StringRes titleRes: Int = R.string.chat_image_source_title,
    @StringRes messageRes: Int? = null,
    onDrawClick: (() -> Unit)? = null,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(titleRes)) },
        text = {
            Column {
                messageRes?.let { message ->
                    Text(text = stringResource(message), style = MaterialTheme.typography.bodyMedium)
                    Spacer(modifier = Modifier.height(Spacing.md))
                }
                onDrawClick?.let { draw ->
                    Button(onClick = draw, modifier = Modifier.fillMaxWidth()) {
                        Text(stringResource(R.string.chat_image_source_draw))
                    }
                    Spacer(modifier = Modifier.height(Spacing.sm))
                }
                Button(onClick = onCameraClick, modifier = Modifier.fillMaxWidth()) {
                    Text(stringResource(R.string.chat_image_source_camera))
                }
                Spacer(modifier = Modifier.height(Spacing.sm))
                Button(onClick = onGalleryClick, modifier = Modifier.fillMaxWidth()) {
                    Text(stringResource(R.string.chat_image_source_gallery))
                }
            }
        },
        confirmButton = {},
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(stringResource(android.R.string.cancel))
            }
        },
    )
}

/**
 * Camera capture that asks for the CAMERA grant first. Declaring the permission without holding it
 * makes ACTION_IMAGE_CAPTURE throw SecurityException, which reaches the main thread and stops the
 * app, so the grant is checked (and requested) before the intent goes out. A denied grant, a device
 * with no camera app and a cancelled capture all report null.
 */
@Composable
fun rememberCameraCapture(onResult: (Uri?) -> Unit): () -> Unit {
    val context = LocalContext.current
    val deniedText = stringResource(R.string.camera_permission_denied)
    val unavailableText = stringResource(R.string.camera_unavailable)
    val pendingUri = rememberSaveable { mutableStateOf<Uri?>(null) }
    val cameraLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.TakePicture(),
    ) { isSuccess ->
        val uri = pendingUri.value
        pendingUri.value = null
        onResult(if (isSuccess) uri else null)
    }

    val reportUnavailable = {
        pendingUri.value = null
        Toast.makeText(context, unavailableText, Toast.LENGTH_LONG).show()
        onResult(null)
    }

    fun launchCamera() {
        val uri = newCameraOutputUri(context)
        pendingUri.value = uri
        // A camera-less device has no handler, and a grant can be revoked between check and launch.
        try {
            cameraLauncher.launch(uri)
        } catch (_: ActivityNotFoundException) {
            reportUnavailable()
        } catch (_: SecurityException) {
            reportUnavailable()
        }
    }

    val permissionLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.RequestPermission(),
    ) { granted ->
        if (granted) {
            launchCamera()
        } else {
            Toast.makeText(context, deniedText, Toast.LENGTH_LONG).show()
            onResult(null)
        }
    }

    return {
        val granted = ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) ==
            PackageManager.PERMISSION_GRANTED
        if (granted) launchCamera() else permissionLauncher.launch(Manifest.permission.CAMERA)
    }
}

fun newCameraOutputUri(context: Context): Uri {
    val photoFile = File(context.cacheDir, "camera_photos/JPEG_${System.currentTimeMillis()}.jpg")
        .apply { parentFile?.mkdirs() }

    return FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", photoFile)
}
