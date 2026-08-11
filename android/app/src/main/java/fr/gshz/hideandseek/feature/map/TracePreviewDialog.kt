package fr.gshz.hideandseek.feature.map

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import coil3.request.ImageRequest
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.Spacing

/**
 * The generated PNG is what the seekers will judge, so the hider always sees it before it is sent and
 * can go back to the map with the drawing untouched.
 */
@Composable
internal fun TracePreviewDialog(
    review: TraceReviewState,
    onSend: () -> Unit,
    onKeepEditing: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = { if (!review.isSending) onKeepEditing() },
        title = { Text(stringResource(R.string.trace_preview_title)) },
        text = {
            Column(
                verticalArrangement = Arrangement.spacedBy(Spacing.sm),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                AsyncImage(
                    model = ImageRequest.Builder(LocalContext.current).data(review.imageUri).build(),
                    contentDescription = stringResource(R.string.trace_preview_title),
                    contentScale = ContentScale.Fit,
                    modifier = Modifier.fillMaxWidth().aspectRatio(TRACE_ASPECT_RATIO),
                )
                if (review.isSending) {
                    CircularProgressIndicator(modifier = Modifier.size(SPINNER_SIZE_DP.dp), strokeWidth = 2.dp)
                }
                if (review.sendFailed) {
                    Text(
                        text = stringResource(R.string.trace_send_failed),
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.error,
                    )
                }
            }
        },
        confirmButton = {
            TextButton(onClick = onSend, enabled = !review.isSending) {
                Text(stringResource(R.string.trace_send))
            }
        },
        dismissButton = {
            TextButton(onClick = onKeepEditing, enabled = !review.isSending) {
                Text(stringResource(R.string.trace_keep_editing))
            }
        },
    )
}

private const val TRACE_ASPECT_RATIO = 4f / 3f
private const val SPINNER_SIZE_DP = 24
