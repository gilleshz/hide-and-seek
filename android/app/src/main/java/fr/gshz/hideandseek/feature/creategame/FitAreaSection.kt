package fr.gshz.hideandseek.feature.creategame

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Place
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.resolve
import fr.gshz.hideandseek.core.ui.theme.Spacing

@Composable
internal fun FitAreaSection(
    uiState: CreateGameUiState,
    actions: CreateGameActions,
    onReviewAreas: () -> Unit,
) {
    if (!uiState.showFitArea) return
    val hasLines = uiState.expandedSelectedLineIds.isNotEmpty() || uiState.selectedGtfsRouteKeys.isNotEmpty()
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        OutlinedButton(
            onClick = actions.onFitAreaFromLines,
            enabled = hasLines && !uiState.isFittingArea && !uiState.isLoading,
            contentPadding = PaddingValues(horizontal = Spacing.md, vertical = Spacing.xs),
            modifier = Modifier.align(Alignment.Start),
        ) {
            if (uiState.isFittingArea) {
                CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                Spacer(Modifier.width(Spacing.sm))
                Text(stringResource(R.string.area_fit_loading))
            } else {
                Icon(
                    Icons.Filled.Place,
                    contentDescription = null,
                    modifier = Modifier.size(18.dp),
                )
                Spacer(Modifier.width(Spacing.xs))
                Text(stringResource(R.string.area_fit_action))
            }
        }
        uiState.fitAreaError?.let { error ->
            Text(
                text = error.resolve(),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.error,
            )
        }
        uiState.fitAreaCount?.let { count ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    text = stringResource(R.string.area_fit_done, count),
                    style = MaterialTheme.typography.bodySmall,
                    modifier = Modifier.weight(1f),
                )
                TextButton(onClick = onReviewAreas) {
                    Text(stringResource(R.string.area_fit_review))
                }
            }
        }
    }
}
