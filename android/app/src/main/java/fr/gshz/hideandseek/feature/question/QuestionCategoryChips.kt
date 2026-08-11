package fr.gshz.hideandseek.feature.question

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.QuestionCategory

private val CATEGORY_ORDER = listOf(
    QuestionCategory.Radar,
    QuestionCategory.Thermometer,
    QuestionCategory.Measuring,
    QuestionCategory.Matching,
    QuestionCategory.Tentacles,
    QuestionCategory.Photos,
)

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun QuestionCategoryChips(
    selected: QuestionCategory,
    gameSize: GameSize,
    onSelect: (QuestionCategory) -> Unit,
    modifier: Modifier = Modifier,
) {
    FlowRow(
        modifier = modifier,
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
        verticalArrangement = Arrangement.spacedBy(Spacing.xs),
    ) {
        CATEGORY_ORDER.forEach { category ->
            if (category == QuestionCategory.Tentacles && gameSize == GameSize.Small) return@forEach
            QuestionCategoryChip(
                category = category,
                selected = category == selected,
                onClick = { onSelect(category) },
            )
        }
    }
}

@Composable
private fun QuestionCategoryChip(category: QuestionCategory, selected: Boolean, onClick: () -> Unit) {
    FilterChip(
        selected = selected,
        onClick = onClick,
        label = { Text(stringResource(category.labelRes())) },
        leadingIcon = if (selected) {
            { SelectedCheckIcon() }
        } else {
            null
        },
        colors = FilterChipDefaults.filterChipColors(
            containerColor = Color.Transparent,
            labelColor = MaterialTheme.colorScheme.onSurface,
            selectedContainerColor = MaterialTheme.colorScheme.primary,
            selectedLabelColor = MaterialTheme.colorScheme.onPrimary,
            selectedLeadingIconColor = MaterialTheme.colorScheme.onPrimary,
        ),
        border = FilterChipDefaults.filterChipBorder(
            enabled = true,
            selected = selected,
            borderColor = MaterialTheme.colorScheme.outline,
            selectedBorderColor = MaterialTheme.colorScheme.primary,
            selectedBorderWidth = 1.dp,
        ),
    )
}

@Composable
private fun SelectedCheckIcon() {
    Icon(
        imageVector = Icons.Default.Check,
        contentDescription = null,
        modifier = Modifier.size(FilterChipDefaults.IconSize),
    )
}

private fun QuestionCategory.labelRes(): Int = when (this) {
    QuestionCategory.Radar -> R.string.question_category_radar
    QuestionCategory.Thermometer -> R.string.question_category_thermometer
    QuestionCategory.Measuring -> R.string.question_category_measuring
    QuestionCategory.Matching -> R.string.question_category_matching
    QuestionCategory.Tentacles -> R.string.question_category_tentacles
    QuestionCategory.Photos -> R.string.question_category_photos
}

@Preview
@Composable
private fun QuestionCategoryChipsPreview() {
    AppTheme {
        QuestionCategoryChips(
            selected = QuestionCategory.Thermometer,
            gameSize = GameSize.Medium,
            onSelect = {},
            modifier = Modifier.padding(Spacing.md),
        )
    }
}
