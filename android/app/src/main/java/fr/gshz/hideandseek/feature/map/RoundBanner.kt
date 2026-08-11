package fr.gshz.hideandseek.feature.map

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.res.stringResource
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.formatDuration
import fr.gshz.hideandseek.core.ui.formatDurationWords
import fr.gshz.hideandseek.core.ui.theme.BrandColors
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.RoundStatus

@Composable
internal fun RoundBanner(status: RoundStatus?, timerSeconds: Long?, modifier: Modifier = Modifier) {
    val text = when (status) {
        RoundStatus.Hiding -> stringResource(R.string.round_banner_hiding, formatDuration(timerSeconds ?: 0L))
        RoundStatus.Seeking -> stringResource(R.string.round_banner_seeking, formatDuration(timerSeconds ?: 0L))
        // A finished round reads as a result rather than a clock, so its total carries units.
        RoundStatus.Ended -> stringResource(R.string.round_banner_ended, formatDurationWords(timerSeconds ?: 0L))
        RoundStatus.Lobby -> stringResource(R.string.round_banner_not_started)
        null -> return
    }
    val isActive = status == RoundStatus.Hiding || status == RoundStatus.Seeking
    val container = if (isActive) BrandColors.hider else MaterialTheme.colorScheme.surfaceVariant
    val content = if (isActive) BrandColors.onHider else MaterialTheme.colorScheme.onSurfaceVariant
    Surface(
        modifier = modifier.fillMaxWidth(),
        color = container,
        contentColor = content,
        shape = RectangleShape,
    ) {
        Text(
            text = text,
            modifier = Modifier.padding(horizontal = Spacing.lg, vertical = Spacing.sm),
            style = MaterialTheme.typography.titleMedium,
        )
    }
}
