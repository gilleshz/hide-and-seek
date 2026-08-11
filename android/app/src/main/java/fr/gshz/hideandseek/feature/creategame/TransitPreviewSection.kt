package fr.gshz.hideandseek.feature.creategame

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.UiText
import fr.gshz.hideandseek.core.ui.resolve
import fr.gshz.hideandseek.core.ui.theme.Spacing

/**
 * The preview follows the selection on its own, so this only reports progress. Retry exists because
 * a failed fetch would otherwise need a pointless extra tick.
 */
@Composable
internal fun TransitPreviewSection(
    uiState: CreateGameUiState,
    actions: CreateGameActions,
    modifier: Modifier = Modifier,
) {
    val error = uiState.transitPreviewError
    if (!uiState.isLoadingTransitPreview && error == null) return

    Column(modifier = modifier, verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        if (uiState.isLoadingTransitPreview) {
            TransitPreviewProgress()
        }
        if (error != null) {
            TransitPreviewFailure(error = error, onRetry = actions.onRetryTransitPreview)
        }
    }
}

@Composable
private fun TransitPreviewProgress() {
    Row(verticalAlignment = Alignment.CenterVertically) {
        CircularProgressIndicator(modifier = Modifier.size(16.dp), strokeWidth = 2.dp)
        Spacer(Modifier.width(Spacing.sm))
        Text(
            text = stringResource(R.string.transit_preview_loading),
            style = MaterialTheme.typography.bodySmall,
        )
    }
}

@Composable
private fun TransitPreviewFailure(error: UiText, onRetry: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(
            text = error.resolve(),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.error,
            modifier = Modifier.weight(1f),
        )
        TextButton(onClick = onRetry) {
            Text(stringResource(R.string.transit_preview_retry))
        }
    }
}
