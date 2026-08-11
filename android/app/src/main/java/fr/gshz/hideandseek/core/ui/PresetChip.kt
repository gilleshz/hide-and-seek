package fr.gshz.hideandseek.core.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.wrapContentWidth
import androidx.compose.foundation.selection.selectable
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing

/**
 * A selectable preset tile for radius/distance choices, reads as a tappable card
 * that fills its container and highlights with the primary color when selected.
 */
@Composable
fun PresetChip(
    label: String,
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    isRepeat: Boolean = false,
) {
    val container = when {
        selected -> MaterialTheme.colorScheme.primary
        isRepeat -> MaterialTheme.colorScheme.errorContainer
        else -> MaterialTheme.colorScheme.surfaceContainerHigh
    }
    val content = when {
        selected -> MaterialTheme.colorScheme.onPrimary
        isRepeat -> MaterialTheme.colorScheme.onErrorContainer
        else -> MaterialTheme.colorScheme.onSurface
    }
    val border = when {
        selected -> MaterialTheme.colorScheme.primary
        isRepeat -> MaterialTheme.colorScheme.error
        else -> MaterialTheme.colorScheme.outlineVariant
    }
    Surface(
        modifier = modifier
            .heightIn(min = 48.dp)
            .selectable(selected = selected, enabled = enabled, onClick = onClick),
        color = container,
        contentColor = content,
        shape = MaterialTheme.shapes.small,
        border = androidx.compose.foundation.BorderStroke(1.dp, border),
    ) {
        Box(
            contentAlignment = Alignment.Center,
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
        ) {
            Text(
                text = label,
                style = MaterialTheme.typography.titleMedium,
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Preview
@Composable
private fun PresetChipPreview() {
    AppTheme {
        Row(
            modifier = Modifier.fillMaxWidth().padding(Spacing.md),
            horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            PresetChip(label = "1 km", selected = true, onClick = {}, modifier = Modifier.wrapContentWidth())
            PresetChip(label = "5 km", selected = false, onClick = {}, modifier = Modifier.wrapContentWidth())
        }
    }
}
