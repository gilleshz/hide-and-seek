package fr.gshz.hideandseek.feature.lobby

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.RemoveRedEye
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.BrandColors
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.Side

private val teamSides = listOf(Side.Hider, Side.Seeker)
private const val DISABLED_ALPHA = 0.45f

@Composable
internal fun SidePicker(
    selected: Side?,
    canChoose: (Side) -> Boolean,
    onSideClick: (Side) -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(modifier = modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            teamSides.forEach { side ->
                SideChoiceCard(
                    side = side,
                    selected = selected == side,
                    enabled = canChoose(side),
                    onClick = { onSideClick(side) },
                    modifier = Modifier.weight(1f),
                )
            }
        }
        SpectatorChoiceCard(
            selected = selected == Side.Spectator,
            enabled = canChoose(Side.Spectator),
            onClick = { onSideClick(Side.Spectator) },
        )
    }
}

@Composable
private fun SideChoiceCard(
    side: Side,
    selected: Boolean,
    enabled: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val accent = if (side == Side.Hider) BrandColors.hider else BrandColors.seeker
    val onAccent = if (side == Side.Hider) BrandColors.onHider else BrandColors.onSeeker
    val container = if (selected) accent else MaterialTheme.colorScheme.surfaceContainerHigh
    val content = if (selected) onAccent else MaterialTheme.colorScheme.onSurfaceVariant
    Surface(
        onClick = onClick,
        enabled = enabled,
        modifier = modifier.alpha(if (enabled) 1f else DISABLED_ALPHA),
        shape = MaterialTheme.shapes.medium,
        color = container,
        contentColor = content,
        border = if (selected) null else BorderStroke(1.dp, accent),
    ) {
        Column(
            modifier = Modifier.padding(vertical = Spacing.md, horizontal = Spacing.sm),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            Icon(imageVector = side.icon(), contentDescription = null, modifier = Modifier.size(28.dp))
            Row(
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
            ) {
                Text(text = stringResource(side.labelRes()), style = MaterialTheme.typography.titleMedium)
                if (selected) {
                    Icon(imageVector = Icons.Filled.Check, contentDescription = null, modifier = Modifier.size(18.dp))
                }
            }
        }
    }
}

@Composable
private fun SpectatorChoiceCard(
    selected: Boolean,
    enabled: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val container = if (selected) {
        MaterialTheme.colorScheme.surfaceContainerHighest
    } else {
        MaterialTheme.colorScheme.surfaceContainerHigh
    }
    val content = if (selected) {
        MaterialTheme.colorScheme.onSurface
    } else {
        MaterialTheme.colorScheme.onSurfaceVariant
    }
    Surface(
        onClick = onClick,
        enabled = enabled,
        modifier = modifier.fillMaxWidth().alpha(if (enabled) 1f else DISABLED_ALPHA),
        shape = MaterialTheme.shapes.medium,
        color = container,
        contentColor = content,
        border = if (selected) {
            BorderStroke(2.dp, MaterialTheme.colorScheme.outline)
        } else {
            BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)
        },
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.md),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.md),
        ) {
            Icon(imageVector = Side.Spectator.icon(), contentDescription = null, modifier = Modifier.size(28.dp))
            Column(modifier = Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
                SpectatorCardTitle(selected)
                Text(
                    text = stringResource(
                        if (selected) R.string.lobby_spectate_helper_selected else R.string.lobby_spectate_helper,
                    ),
                    style = MaterialTheme.typography.bodySmall,
                )
            }
        }
    }
}

@Composable
private fun SpectatorCardTitle(selected: Boolean) {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
    ) {
        Text(text = stringResource(Side.Spectator.labelRes()), style = MaterialTheme.typography.titleMedium)
        if (selected) {
            Icon(imageVector = Icons.Filled.Check, contentDescription = null, modifier = Modifier.size(18.dp))
        }
    }
}

@Composable
internal fun RolesLockedHint() {
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
    ) {
        Icon(
            imageVector = Icons.Filled.Info,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(16.dp),
        )
        Text(
            text = stringResource(R.string.lobby_roles_locked),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

private fun Side.labelRes(): Int = when (this) {
    Side.Hider -> R.string.lobby_side_hider
    Side.Seeker -> R.string.lobby_side_seeker
    Side.Spectator -> R.string.lobby_side_spectator
}

private fun Side.icon(): ImageVector = when (this) {
    Side.Hider -> Icons.Filled.VisibilityOff
    Side.Seeker -> Icons.Filled.Visibility
    Side.Spectator -> Icons.Filled.RemoveRedEye
}
