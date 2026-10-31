package fr.gshz.hideandseek.feature.map

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Flag
import androidx.compose.material.icons.filled.HourglassEmpty
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.formatDuration
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.RoundStatus

private data class StatusStyle(
    val label: Int,
    val icon: ImageVector,
    val tint: Color,
    val showsTimer: Boolean,
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun MapStatusBar(status: RoundStatus?, timerSeconds: Long?, modifier: Modifier = Modifier) {
    if (status == null) return
    val style = statusStyle(status)
    TopAppBar(
        modifier = modifier,
        title = {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
            ) {
                Icon(style.icon, contentDescription = null, tint = style.tint, modifier = Modifier.size(20.dp))
                Text(stringResource(style.label), style = MaterialTheme.typography.titleMedium)
                if (style.showsTimer) {
                    Text(
                        text = formatDuration(timerSeconds ?: 0L),
                        style = MaterialTheme.typography.titleMedium.copy(fontFeatureSettings = "tnum"),
                    )
                }
            }
        },
    )
}

@Composable
private fun statusStyle(status: RoundStatus): StatusStyle = when (status) {
    RoundStatus.Hiding -> StatusStyle(
        R.string.round_status_hiding,
        Icons.Filled.VisibilityOff,
        MaterialTheme.colorScheme.secondary,
        showsTimer = true,
    )
    RoundStatus.Seeking -> StatusStyle(
        R.string.round_status_seeking,
        Icons.Filled.Search,
        MaterialTheme.colorScheme.tertiary,
        showsTimer = true,
    )
    RoundStatus.Ended -> StatusStyle(
        R.string.round_status_ended,
        Icons.Filled.Flag,
        MaterialTheme.colorScheme.onSurfaceVariant,
        showsTimer = true,
    )
    RoundStatus.Lobby -> StatusStyle(
        R.string.round_status_not_started,
        Icons.Filled.HourglassEmpty,
        MaterialTheme.colorScheme.onSurfaceVariant,
        showsTimer = false,
    )
}

@Preview(showBackground = true)
@Composable
private fun MapStatusBarPreview() {
    AppTheme {
        MapStatusBar(status = RoundStatus.Hiding, timerSeconds = 754L)
    }
}
