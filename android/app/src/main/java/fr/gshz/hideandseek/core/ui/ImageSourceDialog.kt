package fr.gshz.hideandseek.core.ui

import android.content.Context
import android.net.Uri
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
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

fun newCameraOutputUri(context: Context): Uri {
    val photoFile = File(context.cacheDir, "camera_photos/JPEG_${System.currentTimeMillis()}.jpg")
        .apply { parentFile?.mkdirs() }

    return FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", photoFile)
}
