package fr.gshz.hideandseek.core.ui

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.annotation.StringRes
import androidx.compose.runtime.Composable
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import fr.gshz.hideandseek.R

/** Camera or gallery, with the permission and FileProvider handling already wired. */
@Composable
fun rememberPhotoSourcePicker(
    onPicked: (String) -> Unit,
    onCancelled: () -> Unit = {},
    @StringRes titleRes: Int = R.string.chat_image_source_title,
    @StringRes messageRes: Int? = null,
): () -> Unit {
    val showSource = rememberSaveable { mutableStateOf(false) }
    val consume: (Uri?) -> Unit = { uri ->
        if (uri == null) onCancelled() else onPicked(uri.toString())
    }
    val galleryLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.PickVisualMedia(),
    ) { uri -> consume(uri) }
    val capturePhoto = rememberCameraCapture(consume)

    if (showSource.value) {
        ImageSourceDialog(
            onCameraClick = {
                showSource.value = false
                capturePhoto()
            },
            onGalleryClick = {
                showSource.value = false
                galleryLauncher.launch(
                    PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly),
                )
            },
            onDismiss = {
                showSource.value = false
                onCancelled()
            },
            titleRes = titleRes,
            messageRes = messageRes,
        )
    }

    return { showSource.value = true }
}
