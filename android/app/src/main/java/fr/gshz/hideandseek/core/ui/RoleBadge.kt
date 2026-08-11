package fr.gshz.hideandseek.core.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.HelpOutline
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.BrandColors
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.Side

/**
 * A colored chip that names a player's [Side] using the role accent colors
 * (amber = hider, blue = seeker) so roles are recognisable at a glance.
 */
@Composable
fun RoleBadge(side: Side?, modifier: Modifier = Modifier) {
    val container = when (side) {
        Side.Hider -> BrandColors.hiderContainer
        Side.Seeker -> BrandColors.seekerContainer
        null -> MaterialTheme.colorScheme.surfaceContainerHighest
    }
    val content = when (side) {
        Side.Hider -> BrandColors.onHiderContainer
        Side.Seeker -> BrandColors.onSeekerContainer
        null -> MaterialTheme.colorScheme.onSurfaceVariant
    }
    Surface(
        modifier = modifier,
        color = container,
        contentColor = content,
        shape = MaterialTheme.shapes.small,
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.sm, vertical = Spacing.xs),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            Icon(imageVector = side.icon(), contentDescription = null, modifier = Modifier.size(16.dp))
            Text(text = stringResource(side.labelRes()), style = MaterialTheme.typography.labelLarge)
        }
    }
}

private fun Side?.icon(): ImageVector = when (this) {
    Side.Hider -> Icons.Filled.VisibilityOff
    Side.Seeker -> Icons.Filled.Visibility
    null -> Icons.AutoMirrored.Filled.HelpOutline
}

private fun Side?.labelRes(): Int = when (this) {
    Side.Hider -> R.string.lobby_side_hider
    Side.Seeker -> R.string.lobby_side_seeker
    null -> R.string.lobby_side_unassigned
}

@Preview
@Composable
private fun RoleBadgePreview() {
    AppTheme {
        Row(
            modifier = Modifier.padding(Spacing.md),
            horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            RoleBadge(Side.Hider)
            RoleBadge(Side.Seeker)
            RoleBadge(null)
        }
    }
}
