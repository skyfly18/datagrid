<?php declare(strict_types = 1);

namespace Contributte\Datagrid;

use Contributte\Datagrid\AggregationFunction\TDatagridAggregationFunction;
use Contributte\Datagrid\Column\Action;
use Contributte\Datagrid\Column\ActionCallback;
use Contributte\Datagrid\Column\Column;
use Contributte\Datagrid\Column\ColumnDateTime;
use Contributte\Datagrid\Column\ColumnLink;
use Contributte\Datagrid\Column\ColumnNumber;
use Contributte\Datagrid\Column\ColumnStatus;
use Contributte\Datagrid\Column\ColumnText;
use Contributte\Datagrid\Column\ItemDetail;
use Contributte\Datagrid\Column\MultiAction;
use Contributte\Datagrid\Components\DatagridPaginator\DatagridPaginator;
use Contributte\Datagrid\DataSource\IDataSource;
use Contributte\Datagrid\Exception\DatagridColumnNotFoundException;
use Contributte\Datagrid\Exception\DatagridException;
use Contributte\Datagrid\Exception\DatagridFilterNotFoundException;
use Contributte\Datagrid\Exception\DatagridHasToBeAttachedToPresenterComponentException;
use Contributte\Datagrid\Export\Export;
use Contributte\Datagrid\Export\ExportCsv;
use Contributte\Datagrid\Filter\Filter;
use Contributte\Datagrid\Filter\FilterDate;
use Contributte\Datagrid\Filter\FilterDateRange;
use Contributte\Datagrid\Filter\FilterMultiSelect;
use Contributte\Datagrid\Filter\FilterRange;
use Contributte\Datagrid\Filter\FilterSelect;
use Contributte\Datagrid\Filter\FilterText;
use Contributte\Datagrid\Filter\IFilterDate;
use Contributte\Datagrid\Filter\SubmitButton;
use Contributte\Datagrid\GroupAction\GroupAction;
use Contributte\Datagrid\GroupAction\GroupActionCollection;
use Contributte\Datagrid\GroupAction\GroupButtonAction;
use Contributte\Datagrid\InlineEdit\InlineAdd;
use Contributte\Datagrid\InlineEdit\InlineEdit;
use Contributte\Datagrid\Localization\SimpleTranslator;
use Contributte\Datagrid\Storage\IStateStorage;
use Contributte\Datagrid\Storage\NoopStateStorage;
use Contributte\Datagrid\Storage\SessionStateStorage;
use Contributte\Datagrid\Toolbar\ToolbarButton;
use Contributte\Datagrid\Utils\ArraysHelper;
use Contributte\Datagrid\Utils\ItemDetailForm;
use Contributte\Datagrid\Utils\Sorting;
use DateTime;
use InvalidArgumentException;
use Nette\Application\ForbiddenRequestException;
use Nette\Application\IPresenter;
use Nette\Application\Request;
use Nette\Application\UI\Component;
use Nette\Application\UI\Control;
use Nette\Application\UI\Form;
use Nette\Application\UI\Link;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\Template;
use Nette\ComponentModel\IContainer;
use Nette\Forms\Container;
use Nette\Forms\Control as FormControl;
use Nette\Forms\Controls\SubmitButton as FormsSubmitButton;
use Nette\Forms\Form as NetteForm;
use Nette\Http\SessionSection;
use Nette\Localization\Translator;
use Nette\Utils\ArrayHash;
use UnexpectedValueException;

/**
 * @method onRedraw()
 * @method onRender(Datagrid $dataGrid)
 * @method onColumnAdd(string $key, Column $column)
 * @method onColumnShow(string $key)
 * @method onColumnHide(string $key)
 * @method onShowDefaultColumns()
 * @method onShowAllColumns()
 * @method onExport(Datagrid $dataGrid)
 * @method onFiltersAssembled(Filter[] $filters)
 */
class Datagrid extends Control
{

	use TDatagridAggregationFunction;

	private const HIDEABLE_COLUMNS_STORAGE_KEYS = [
		'_grid_hidden_columns',
		'_grid_hidden_columns_manipulated',
	];

	public static string $iconPrefix = 'fa fa-';

	public static string $btnSecondaryClass = 'btn-default btn-secondary';

	/**
	 * Default form method
	 */
	public static string $formMethod = 'post';

	/** @var array|callable[] */
	public array $onRedraw = [];

	/** @var array|callable[] */
	public array $onRender = [];

	/** @var array|callable[] */
	public array $onExport = [];

	/** @var array|callable[] */
	public array $onColumnAdd = [];

	/** @var array|callable[] */
	public array $onColumnShow = [];

	/** @var array|callable[] */
	public array $onColumnHide = [];

	/** @var array|callable[] */
	public array $onShowDefaultColumns = [];

	/** @var array|callable[] */
	public array $onShowAllColumns = [];

	/** @var array|callable[] */
	public array $onFiltersAssembled = [];

	/**
	 * When set to TRUE, datagrid throws an exception
	 *  when tring to get related entity within join and entity does not exist
	 */
	public bool $strictEntityProperty = false;

	/**
	 * When set to TRUE, datagrid throws an exception
	 *  when tring to set filter value, that does not exist (select, multiselect, etc)
	 */
	public bool $strictStorageFilterValues = true;

	/** @persistent */
	public int $page = 1;

	/** @persistent */
	public string|int|null $perPage = null;

	/** @persistent */
	public array $sort = [];

	public array $defaultSort = [];

	public array $defaultFilter = [];

	public bool $defaultFilterUseOnReset = true;

	public bool $defaultSortUseOnReset = true;

	/** @persistent */
	public array $filter = [];

	/** @var callable|null */
	protected $sortCallback = null;

	/** @var callable */
	protected $rowCallback;

	protected array $itemsPerPageList = [10, 20, 50, 'all'];

	protected ?int $defaultPerPage = null;

	protected ?string $templateFile = null;

	/** @var array<Column> */
	protected array $columns = [];

	/** @var array<Action>|array<MultiAction> */
	protected array $actions = [];

	protected ?GroupActionCollection $groupActionCollection = null;

	/** @var array<Filter> */
	protected array $filters = [];

	/** @var array<Export> */
	protected array $exports = [];

	/** @var array<ToolbarButton> */
	protected array $toolbarButtons = [];

	protected ?DataModel $dataModel = null;

	protected string $primaryKey = 'id';

	protected bool $doPaginate = true;

	protected bool $csvExport = true;

	protected bool $csvExportFiltered = true;

	protected bool $sortable = false;

	protected bool $multiSort = false;

	protected string $sortableHandler = 'sort!';

	protected ?string $originalTemplate = null;

	protected array $redrawItem = [];

	protected ?Translator $translator = null;

	protected bool $forceFilterActive = false;

	/** @var callable|null */
	protected $treeViewChildrenCallback = null;

	/** @var callable|null */
	protected $treeViewHasChildrenCallback = null;

	protected ?string $treeViewHasChildrenColumn = null;

	protected bool $outerFilterRendering = false;

	protected int $outerFilterColumnsCount = 2;

	protected bool $collapsibleOuterFilters = true;

	/** @var array|string[] */
	protected array $columnsExportOrder = [];

	protected bool $rememberState = true;

	protected bool $rememberHideableColumnsState = true;

	protected bool $refreshURL = true;

	protected SessionSection $gridSession;

	protected ?ItemDetail $itemsDetail = null;

	protected array $rowConditions = [
		'group_action' => false,
		'action' => [],
	];

	protected array $columnCallbacks = [];

	protected bool $canHideColumns = false;

	protected array $columnsVisibility = [];

	protected ?InlineEdit $inlineEdit = null;

	protected ?InlineAdd $inlineAdd = null;

	protected bool $snippetsSet = false;

	protected bool $someColumnDefaultHide = false;

	protected ?ColumnsSummary $columnsSummary = null;

	protected bool $autoSubmit = true;

	protected ?SubmitButton $filterSubmitButton = null;

	protected bool $hasColumnReset = true;

	protected bool $showSelectedRowsCount = true;

	protected ?IStateStorage $stateStorage = null;

	private ?string $customPaginatorTemplate = null;

	private ?string $componentFullName = null;

	public function __construct(?IContainer $parent = null, ?string $name = null)
	{
		if ($parent !== null) {
			$parent->addComponent($this, $name);
		}

		/**
		 * Try to find previous filters, pagination, perPage and other values in storage
		 */
		$this->onRender[] = [$this, 'findStorageValues'];
		$this->onExport[] = [$this, 'findStorageValues'];

		/**
		 * Find default filter values
		 */
		$this->onRender[] = [$this, 'findDefaultFilter'];
		$this->onExport[] = [$this, 'findDefaultFilter'];

		/**
		 * Find default sort
		 */
		$this->onRender[] = [$this, 'findDefaultSort'];
		$this->onExport[] = [$this, 'findDefaultSort'];

		/**
		 * Find default items per page
		 */
		$this->onRender[] = [$this, 'findDefaultPerPage'];

		/**
		 * Notify about that json js extension
		 */
		$this->onFiltersAssembled[] = [$this, 'sendNonEmptyFiltersInPayload'];

		$this->monitor(
			Presenter::class,
			function (Presenter $presenter): void {
				/**
				 * Get session
				 */
				if ($this->rememberState || $this->canHideColumns()) {
					$this->gridSession = $presenter->getSession($this->getSessionSectionName());
				}

				$this->componentFullName = $this->lookupPath();
			}
		);
	}

	public function getStateStorage(): IStateStorage
	{
		if ($this->stateStorage === null) {
			return $this->rememberState || $this->canHideColumns()
				? new SessionStateStorage($this->gridSession)
				: new NoopStateStorage();
		}

		return $this->stateStorage;
	}

	public function setStateStorage(IStateStorage $stateStorage): self
	{
		$this->stateStorage = $stateStorage;

		return $this;
	}

	/********************************************************************************
	 *                                  RENDERING *
	 ********************************************************************************/
	public function render(): void
	{
		/**
		 * Check whether datagrid has set some columns, initiated data source, etc
		 */
		if (!($this->dataModel instanceof DataModel)) {
			throw new DatagridException('You have to set a data source first.');
		}

		if ($this->columns === []) {
			throw new DatagridException('You have to add at least one column.');
		}

		$template = $this->getTemplate();

		if (!$template instanceof Template) {
			throw new UnexpectedValueException();
		}

		$template->setTranslator($this->getTranslator());

		/**
		 * Invoke possible events
		 */
		$this->onRender($this);

		/**
		 * Prepare data for rendering (datagrid may render just one item)
		 */
		$rows = [];

		$items = $this->redrawItem !== [] ? $this->dataModel->filterRow($this->redrawItem) : $this->dataModel->filterData(
			$this->getPaginator(),
			$this->createSorting($this->sort, $this->sortCallback),
			$this->assembleFilters()
		);

		$hasGroupActionOnRows = false;

		foreach ($items as $item) {
			$rows[] = $row = new Row($this, $item, $this->getPrimaryKey());

			if (!$hasGroupActionOnRows && $row->hasGroupAction()) {
				$hasGroupActionOnRows = true;
			}

			if ($this->rowCallback !== null) {
				($this->rowCallback)($item, $row->getControl());
			}

			/**
			 * Walkaround for item snippet - snippet is the <tr> element and its class has to be also updated
			 */
			if ($this->redrawItem !== []) {
				$this->getPresenterInstance()->payload->_datagrid_redrawItem_class = $row->getControlClass();
				$this->getPresenterInstance()->payload->_datagrid_redrawItem_id = $row->getId();
			}
		}

		if ($hasGroupActionOnRows) {
			$hasGroupActionOnRows = $this->hasGroupActions();
		}

		if ($this->isTreeView()) {
			$template->add('treeViewHasChildrenColumn', $this->treeViewHasChildrenColumn);
		}

		$template->rows = $rows;

		$template->columns = $this->getColumns();
		$template->actions = $this->actions;
		$template->exports = $this->exports;
		$template->filters = $this->filters;
		$template->toolbarButtons = $this->toolbarButtons;
		$template->aggregationFunctions = $this->getAggregationFunctions();
		$template->multipleAggregationFunction = $this->getMultipleAggregationFunction();

		$template->filter_active = $this->isFilterActive();
		$template->originalTemplate = $this->getOriginalTemplateFile();
		$template->iconPrefix = static::$iconPrefix;
		$template->btnSecondaryClass = static::$btnSecondaryClass;
		$template->itemsDetail = $this->itemsDetail;
		$template->columnsVisibility = $this->getColumnsVisibility();
		$template->columnsSummary = $this->columnsSummary;

		$template->inlineEdit = $this->inlineEdit;
		$template->inlineAdd = $this->inlineAdd;

		$template->hasGroupActions = $this->hasGroupActions();
		$template->hasGroupActionOnRows = $hasGroupActionOnRows;

		/**
		 * Walkaround for Latte (does not know $form in snippet in {form} etc)
		 */
		$template->filter = $this['filter'];

		/**
		 * Set template file and render it
		 */
		$template->setFile($this->getTemplateFile());
		$template->render();
	}


	/********************************************************************************
	 *                                 ROW CALLBACK *
	 ********************************************************************************/

	/**
	 * Each row can be modified with user defined callback
	 *
	 * @return static
	 */
	public function setRowCallback(callable $callback): self
	{
		$this->rowCallback = $callback;

		return $this;
	}


	/********************************************************************************
	 *                                 DATA SOURCE *
	 ********************************************************************************/

	/**
	 * @return static
	 */
	public function setPrimaryKey(string $primaryKey): self
	{
		if ($this->dataModel instanceof DataModel) {
			throw new DatagridException('Please set datagrid primary key before setting datasource.');
		}

		$this->primaryKey = $primaryKey;

		return $this;
	}

	/**
	 * @return static
	 * @throws InvalidArgumentException
	 */
	public function setDataSource(mixed $source): self
	{
		$this->dataModel = new DataModel($source, $this->primaryKey);

		$this->dataModel->onBeforeFilter[] = [$this, 'beforeDataModelFilter'];
		$this->dataModel->onAfterFilter[] = [$this, 'afterDataModelFilter'];
		$this->dataModel->onAfterPaginated[] = [$this, 'afterDataModelPaginated'];

		return $this;
	}

	public function getDataSource(): IDataSource|array|null
	{
		return isset($this->dataModel)
			? $this->dataModel->getDataSource()
			: null;
	}


	/********************************************************************************
	 *                                  TEMPLATING *
	 ********************************************************************************/

	/**
	 * @return static
	 */
	public function setTemplateFile(string $templateFile): self
	{
		$this->templateFile = $templateFile;

		return $this;
	}

	public function getTemplateFile(): string
	{
		return $this->templateFile ?? $this->getOriginalTemplateFile();
	}

	public function getOriginalTemplateFile(): string
	{
		return __DIR__ . '/templates/datagrid.latte';
	}

	/********************************************************************************
	 *                                   SORTING *
	 ********************************************************************************/

	/**
	 * @return static
	 */
	public function setDefaultSort(string|array $sort, bool $useOnReset = true): self
	{
		$sort = is_string($sort)
			? [$sort => 'ASC']
			: $sort;

		$this->defaultSort = $sort;
		$this->defaultSortUseOnReset = $useOnReset;

		return $this;
	}

	/**
	 * Return default sort for column, if specified
	 */
	public function getColumnDefaultSort(string $columnKey): ?string
	{
		if (isset($this->defaultSort[$columnKey])) {
			return $this->defaultSort[$columnKey];
		}

		return null;
	}

	/**
	 * User may set default sorting, apply it
	 */
	public function findDefaultSort(): void
	{
		if ($this->sort !== []) {
			return;
		}

		if ((bool) $this->getStorageData('_grid_has_sorted')) {
			return;
		}

		if ($this->defaultSort !== []) {
			$this->sort = $this->defaultSort;
		}

		$this->saveStorageData('_grid_sort', $this->sort);
	}

	/**
	 * @return static
	 * @throws DatagridException
	 */
	public function setSortable(bool $sortable = true): self
	{
		if ($this->getItemsDetail() !== null) {
			throw new DatagridException('You can not use both sortable datagrid and items detail.');
		}

		$this->sortable = $sortable;

		return $this;
	}

	public function isSortable(): bool
	{
		return $this->sortable;
	}

	/**
	 * @return static
	 */
	public function setMultiSortEnabled(bool $multiSort = true): self
	{
		$this->multiSort = $multiSort;

		return $this;
	}

	public function isMultiSortEnabled(): bool
	{
		return $this->multiSort;
	}

	/**
	 * @return static
	 */
	public function setSortableHandler(string $handler = 'sort!'): self
	{
		$this->sortableHandler = $handler;

		return $this;
	}

	public function getSortableHandler(): string
	{
		return $this->sortableHandler;
	}

	public function getSortNext(Column $column): array
	{
		$sort = $column->getSortNext();

		if ($this->isMultiSortEnabled()) {
			$sort = array_merge($this->sort, $sort);
		}

		return array_filter($sort);
	}

	/********************************************************************************
	 *                                  TREE VIEW *
	 ********************************************************************************/
	public function isTreeView(): bool
	{
		return $this->treeViewChildrenCallback !== null;
	}

	/**
	 * @return static
	 */
	public function setTreeView(
		callable $getChildrenCallback,
		string|callable $treeViewHasChildrenColumn = 'has_children'
	): self
	{
		if (is_callable($treeViewHasChildrenColumn)) {
			$this->treeViewHasChildrenCallback = $treeViewHasChildrenColumn;
			$treeViewHasChildrenColumn = null;
		}

		$this->treeViewChildrenCallback = $getChildrenCallback;
		$this->treeViewHasChildrenColumn = $treeViewHasChildrenColumn;

		/**
		 * Torn off pagination
		 */
		$this->setPagination(false);

		/**
		 * Set tree view template file
		 */
		if ($this->templateFile === null) {
			$this->setTemplateFile(__DIR__ . '/templates/datagrid_tree.latte');
		}

		return $this;
	}

	public function hasTreeViewChildrenCallback(): bool
	{
		return is_callable($this->treeViewHasChildrenCallback);
	}

	public function treeViewChildrenCallback(mixed $item): bool
	{
		if ($this->treeViewHasChildrenCallback === null) {
			throw new UnexpectedValueException();
		}

		return (bool) call_user_func($this->treeViewHasChildrenCallback, $item);
	}

	/********************************************************************************
	 *                                    COLUMNS *
	 ********************************************************************************/
	public function addColumnText(
		string $key,
		string $name,
		?string $column = null
	): ColumnText
	{
		$column ??= $key;

		$columnText = new ColumnText($this, $key, $column, $name);
		$this->addColumn($key, $columnText);

		return $columnText;
	}

	public function addColumnLink(
		string $key,
		string $name,
		?string $href = null,
		?string $column = null,
		?array $params = null
	): ColumnLink
	{
		$column ??= $key;
		$href ??= $key;

		if ($params === null) {
			$params = [$this->primaryKey];
		}

		$columnLink = new ColumnLink($this, $key, $column, $name, $href, $params);
		$this->addColumn($key, $columnLink);

		return $columnLink;
	}

	public function addColumnNumber(
		string $key,
		string $name,
		?string $column = null
	): ColumnNumber
	{
		$column ??= $key;

		$columnNumber = new ColumnNumber($this, $key, $column, $name);
		$this->addColumn($key, $columnNumber);

		return $columnNumber;
	}

	public function addColumnDateTime(
		string $key,
		string $name,
		?string $column = null
	): ColumnDateTime
	{
		$column ??= $key;

		$columnDateTime = new ColumnDateTime($this, $key, $column, $name);
		$this->addColumn($key, $columnDateTime);

		return $columnDateTime;
	}

	public function addColumnStatus(
		string $key,
		string $name,
		?string $column = null
	): ColumnStatus
	{
		$column ??= $key;

		$columnStatus = new ColumnStatus($this, $key, $column, $name);
		$this->addColumn($key, $columnStatus);

		return $columnStatus;
	}

	/**
	 * @throws DatagridColumnNotFoundException
	 */
	public function getColumn(string $key): Column
	{
		if (!isset($this->columns[$key])) {
			throw new DatagridColumnNotFoundException(
				sprintf('There is no column at key [%s] defined.', $key)
			);
		}

		return $this->columns[$key];
	}

	/**
	 * @return static
	 */
	public function removeColumn(string $key): self
	{
		unset($this->columnsVisibility[$key], $this->columns[$key]);

		return $this;
	}

	/********************************************************************************
	 *                                    ACTIONS *
	 ********************************************************************************/
	public function addAction(
		string $key,
		string $name,
		?string $href = null,
		?array $params = null
	): Action
	{
		$this->addActionCheck($key);

		$href ??= $key;

		if ($params === null) {
			$params = [$this->primaryKey];
		}

		return $this->actions[$key] = new Action($this, $key, $href, $name, $params);
	}

	public function addActionCallback(
		string $key,
		string $name,
		?callable $callback = null
	): ActionCallback
	{
		$this->addActionCheck($key);

		$params = ['__id' => $this->primaryKey];

		$this->actions[$key] = $action = new ActionCallback($this, $key, $key, $name, $params);

		if ($callback !== null) {
			$action->onClick[] = $callback;
		}

		return $action;
	}

	public function addMultiAction(string $key, string $name): MultiAction
	{
		$this->addActionCheck($key);

		$action = new MultiAction($this, $key, $name);

		$this->actions[$key] = $action;

		return $action;
	}

	/**
	 * @throws DatagridException
	 */
	public function getAction(string $key): Action|MultiAction
	{
		if (!isset($this->actions[$key])) {
			throw new DatagridException(sprintf('There is no action at key [%s] defined.', $key));
		}

		return $this->actions[$key];
	}

	/**
	 * @return static
	 */
	public function removeAction(string $key): self
	{
		unset($this->actions[$key]);

		return $this;
	}

	public function addFilterText(
		string $key,
		string $name,
		array|string|null $columns = null
	): FilterText
	{
		$columns = $columns === null ? [$key] : (is_string($columns) ? [$columns] : $columns);

		$this->addFilterCheck($key);

		return $this->filters[$key] = new FilterText($this, $key, $name, $columns);
	}

	public function addFilterSelect(
		string $key,
		string $name,
		array $options,
		?string $column = null
	): FilterSelect
	{
		$column ??= $key;

		$this->addFilterCheck($key);

		return $this->filters[$key] = new FilterSelect($this, $key, $name, $options, $column);
	}

	public function addFilterMultiSelect(
		string $key,
		string $name,
		array $options,
		?string $column = null
	): FilterMultiSelect
	{
		$column ??= $key;

		$this->addFilterCheck($key);

		return $this->filters[$key] = new FilterMultiSelect($this, $key, $name, $options, $column);
	}

	public function addFilterDate(string $key, string $name, ?string $column = null): FilterDate
	{
		$column ??= $key;

		$this->addFilterCheck($key);

		return $this->filters[$key] = new FilterDate($this, $key, $name, $column);
	}

	public function addFilterRange(
		string $key,
		string $name,
		?string $column = null,
		string $nameSecond = '-'
	): FilterRange
	{
		$column ??= $key;

		$this->addFilterCheck($key);

		return $this->filters[$key] = new FilterRange($this, $key, $name, $column, $nameSecond);
	}

	/**
	 * @throws DatagridException
	 */
	public function addFilterDateRange(
		string $key,
		string $name,
		?string $column = null,
		string $nameSecond = '-'
	): FilterDateRange
	{
		$column ??= $key;

		$this->addFilterCheck($key);

		return $this->filters[$key] = new FilterDateRange($this, $key, $name, $column, $nameSecond);
	}

	/**
	 * Fill array of Filter\Filter[] with values from $this->filter persistent parameter
	 * Fill array of Column\Column[] with values from $this->sort persistent parameter
	 *
	 * @return array<Filter>
	 */
	public function assembleFilters(): array
	{
		foreach ($this->filter as $key => $value) {
			if (!isset($this->filters[$key])) {
				$this->deleteStorageData($key);

				continue;
			}

			if (is_iterable($value)) {
				if (!ArraysHelper::testEmpty($value)) {
					$this->filters[$key]->setValue($value);
				}
			} else {
				if ($value !== '' && $value !== null) {
					$this->filters[$key]->setValue($value);
				}
			}
		}

		foreach ($this->columns as $key => $column) {
			if (isset($this->sort[$key])) {
				$column->setSort($this->sort[$key]);
			}
		}

		$this->onFiltersAssembled($this->filters);

		return $this->filters;
	}

	/**
	 * @return static
	 */
	public function removeFilter(string $key): self
	{
		unset($this->filters[$key]);

		return $this;
	}

	public function getFilter(string $key): Filter
	{
		if (!isset($this->filters[$key])) {
			throw new DatagridException(sprintf('Filter [%s] is not defined', $key));
		}

		return $this->filters[$key];
	}

	/**
	 * @deprecated Use setStrictStorageFilterValues() instead
	 * @return static
	 */
	public function setStrictSessionFilterValues(bool $strictStorageFilterValues = true): self
	{
		return $this->setStrictStorageFilterValues($strictStorageFilterValues);
	}

	/**
	 * @return static
	 */
	public function setStrictStorageFilterValues(bool $strictStorageFilterValues = true): self
	{
		$this->strictStorageFilterValues = $strictStorageFilterValues;

		return $this;
	}

	/********************************************************************************
	 *                                  FILTERING *
	 ********************************************************************************/
	public function isFilterActive(): bool
	{
		$is_filter = ArraysHelper::testTruthy($this->filter);

		return $is_filter || $this->forceFilterActive;
	}

	public function isFilterDefault(): bool
	{
		return $this->filter === $this->defaultFilter;
	}

	/**
	 * Tell that filter is active from whatever reasons
	 *
	 * @return static
	 */
	public function setFilterActive(): self
	{
		$this->forceFilterActive = true;

		return $this;
	}

	/**
	 * Set filter values (force - overwrite user data)
	 *
	 * @return static
	 */
	public function setFilter(array $filter): self
	{
		$this->filter = $filter;

		$this->saveStorageData('_grid_has_filtered', 1);

		return $this;
	}

	/**
	 * If we want to sent some initial filter
	 *
	 * @return static
	 * @throws DatagridException
	 */
	public function setDefaultFilter(array $defaultFilter, bool $useOnReset = true): self
	{
		foreach ($defaultFilter as $key => $value) {
			/** @var Filter|null $filter */
			$filter = $this->getFilter($key);

			if ($filter === null) {
				throw new DatagridException(
					sprintf('Can not set default value to nonexisting filter [%s]', $key)
				);
			}

			if ($filter instanceof FilterMultiSelect && !is_array($value)) {
				throw new DatagridException(
					sprintf('Default value of filter [%s] - MultiSelect has to be an array', $key)
				);
			}

			if ($filter instanceof FilterRange || $filter instanceof FilterDateRange) {
				if (!is_array($value)) {
					throw new DatagridException(
						sprintf('Default value of filter [%s] - Range/DateRange has to be an array [from/to => ...]', $key)
					);
				}

				$temp = $value;
				unset($temp['from'], $temp['to']);

				if (count($temp) > 0) {
					throw new DatagridException(
						sprintf(
							'Default value of filter [%s] - Range/DateRange can contain only [from/to => ...] values',
							$key
						)
					);
				}
			}
		}

		$this->defaultFilter = $defaultFilter;
		$this->defaultFilterUseOnReset = $useOnReset;

		return $this;
	}

	public function findDefaultFilter(): void
	{
		if ($this->filter !== []) {
			return;
		}

		if ((bool) $this->getStorageData('_grid_has_filtered')) {
			return;
		}

		if ($this->defaultFilter !== []) {
			$this->filter = $this->defaultFilter;
		}

		$storedFilters = $this->getStorageData('_grid_filters', []);
		foreach ($this->filter as $key => $value) {
			$storedFilters[(string) $key] = $value;
		}

		$this->saveStorageData('_grid_filters', $storedFilters);
	}

	public function createComponentFilter(): Form
	{
		$form = new Form($this, 'filter');

		$form->setMethod(static::$formMethod);

		$form->setTranslator($this->getTranslator());

		/**
		 * InlineEdit part
		 */
		$inline_edit_container = $form->addContainer('inline_edit');

		if ($this->inlineEdit instanceof InlineEdit) {
			$inline_edit_container->addSubmit('submit', 'contributte_datagrid.save')
				->setValidationScope([$inline_edit_container]);
			$inline_edit_container->addSubmit('cancel', 'contributte_datagrid.cancel')
				->setValidationScope(null);

			$this->inlineEdit->onControlAdd($inline_edit_container);
			$this->inlineEdit->onControlAfterAdd($inline_edit_container);
		}

		/**
		 * InlineAdd part
		 */
		$inlineAddContainer = $form->addContainer('inline_add');

		if ($this->inlineAdd instanceof InlineAdd) {
			$inlineAddContainer->addSubmit('submit', 'contributte_datagrid.save')
				->setValidationScope([$inlineAddContainer]);
			$inlineAddContainer->addSubmit('cancel', 'contributte_datagrid.cancel')
				->setValidationScope(null)
				->setHtmlAttribute('data-datagrid-cancel-inline-add', true);

			$this->inlineAdd->onControlAdd($inlineAddContainer);
			$this->inlineAdd->onControlAfterAdd($inlineAddContainer);
		}

		/**
		 * ItemDetail form part
		 */
		$itemsDetailForm = $this->getItemDetailForm();

		if ($itemsDetailForm instanceof Container) {
			$form['items_detail_form'] = $itemsDetailForm;
		}

		/**
		 * Filter part
		 */
		$filterContainer = $form->addContainer('filter');

		foreach ($this->filters as $filter) {
			$filter->addToFormContainer($filterContainer);
		}

		if (!$this->hasAutoSubmit()) {
			$filterContainer['submit'] = $this->getFilterSubmitButton();
		}

		/**
		 * Group action part
		 */
		$groupActionContainer = $form->addContainer('group_action');

		if ($this->hasGroupActions()) {
			$this->getGroupActionCollection()->addToFormContainer($groupActionContainer);
		}

		if ($form->isSubmitted() === false) {
			$this->setFilterContainerDefaults($filterContainer, $this->filter);
		}

		/**
		 * Per page part
		 */
		if ($this->isPaginated()) {
			$select = $form->addSelect('perPage', '', $this->getItemsPerPageList())
				->setTranslator(null);

			if ($form->isSubmitted() === false) {
				$select->setValue($this->getPerPage());
			}

			$form->addSubmit('perPage_submit', 'contributte_datagrid.per_page_submit')
				->setValidationScope([$select]);
		}

		$form->onSubmit[] = function (NetteForm $form): void {
			$this->filterSucceeded($form);
		};

		return $form;
	}

	public function setFilterContainerDefaults(Container $container, array $values, ?string $parentKey = null): void
	{
		foreach ($container->getComponents() as $key => $control) {
			if (!isset($values[$key])) {
				continue;
			}

			if ($control instanceof Container) {
				$this->setFilterContainerDefaults($control, (array) $values[$key], (string) $key);

				continue;
			}

			$value = $values[$key];

			if ($value instanceof DateTime) {
				$filter = $parentKey !== null ? $this->getFilter($parentKey) : $this->getFilter((string) $key);

				if ($filter instanceof IFilterDate) {
					$value = $value->format($filter->getPhpFormat());
				}
			}

			try {
				if (!$control instanceof FormControl) {
					throw new UnexpectedValueException();
				}

				$control->setValue($value);

			} catch (InvalidArgumentException $e) {
				if ($this->strictStorageFilterValues) {
					throw $e;
				}
			}
		}
	}

	/**
	 * Set $this->filter values after filter form submitted
	 */
	public function filterSucceeded(NetteForm $form): void
	{
		if ($this->snippetsSet) {
			return;
		}

		if ($this->getPresenterInstance()->isAjax()) {
			if (isset($form['group_action']['submit']) && $form['group_action']['submit']->isSubmittedBy()) {
				return;
			}
		}

		/**
		 * Per page
		 */
		if ($form->getComponent('perPage', false) !== null) {
			$perPage = $form->getComponent('perPage')->getValue();
			$this->saveStorageData('_grid_perPage', $perPage);
			$this->perPage = $perPage;
		}

		/**
		 * Inline edit
		 */
		if (
			isset($form['inline_edit'])
			&& isset($form['inline_edit']['submit'])
			&& isset($form['inline_edit']['cancel'])
			&& $this->inlineEdit !== null
		) {
			$edit = $form['inline_edit'];

			if (
				!$edit instanceof Container
				|| !$edit['submit'] instanceof FormsSubmitButton
				|| !$edit['cancel'] instanceof FormsSubmitButton
			) {
				throw new UnexpectedValueException();
			}

			if ($edit['submit']->isSubmittedBy() || $edit['cancel']->isSubmittedBy()) {
				/** @var string $id */
				$id = $form->getHttpData(Form::DataLine, 'inline_edit[_id]');
				$primaryWhereColumn = $form->getHttpData(Form::DataLine, 'inline_edit[_primary_where_column]');

				if ($edit->getComponent('submit')->isSubmittedBy() && $edit->getErrors() === []) {
					$this->inlineEdit->onSubmit($id, $form->getComponent('inline_edit')->getValues());
					$this->getPresenterInstance()->payload->_datagrid_inline_edited = $id;
					$this->getPresenterInstance()->payload->_datagrid_name = $this->getFullName();
				} else {
					$this->getPresenterInstance()->payload->_datagrid_inline_edit_cancel = $id;
					$this->getPresenterInstance()->payload->_datagrid_name = $this->getFullName();
				}

				if ($edit['submit']->isSubmittedBy() && $this->inlineEdit->onCustomRedraw !== []) {
					$this->inlineEdit->onCustomRedraw('submit');
				} elseif ($edit['cancel']->isSubmittedBy() && $this->inlineEdit->onCustomRedraw !== []) {
					$this->inlineEdit->onCustomRedraw('cancel');
				} else {
					$this->redrawItem($id, $primaryWhereColumn);
					$this->redrawControl('summary');
				}

				return;
			}
		}

		/**
		 * Inline add
		 */
		if (
			isset($form['inline_add'])
			&& isset($form['inline_add']['submit'])
			&& isset($form['inline_add']['cancel'])
			&& $this->inlineAdd !== null
		) {
			$add = $form['inline_add'];

			if (
				!$add instanceof Container
				|| !$add['submit'] instanceof FormsSubmitButton
				|| !$add['cancel'] instanceof FormsSubmitButton
			) {
				throw new UnexpectedValueException();
			}

			if ($add['submit']->isSubmittedBy() || $add['cancel']->isSubmittedBy()) {
				if ($add['submit']->isSubmittedBy() && $add->getErrors() === []) {
					$this->inlineAdd->onSubmit($form->getComponent('inline_add')->getValues());
				}

				$this->redrawControl('tbody');

				$this->onRedraw();

				return;
			}
		}

		/**
		 * Filter itself
		 */
		$values = $form->getComponent('filter')->getValues();

		if (!$values instanceof ArrayHash) {
			throw new UnexpectedValueException();
		}

		$storedFilters = $this->getStorageData('_grid_filters', []);
		foreach ($values as $key => $value) {
			/**
			 * Storage stuff
			 */
			$storedValue = $storedFilters[(string) $key] ?? null;
			if ($this->rememberState && $storedValue !== $value) {
				/**
				 * Has been filter changed?
				 */
				$this->page = 1;
				$this->saveStorageData('_grid_page', 1);
			}

			$storedFilters[(string) $key] = $value;

			/**
			 * Other stuff
			 */
			$this->filter[$key] = $value;
		}

		$this->saveStorageData('_grid_filters', $storedFilters);

		if ($values->count() > 0) {
			$this->saveStorageData('_grid_has_filtered', 1);
		}

		if ($this->getPresenterInstance()->isAjax()) {
			$this->getPresenterInstance()->payload->_datagrid_sort = [];

			foreach ($this->columns as $key => $column) {
				if ($column->isSortable()) {
					$this->getPresenterInstance()->payload->_datagrid_sort[$key] = $this->link('sort!', [
						'sort' => $column->getSortNext(),
					]);
				}
			}
		}

		$this->reload();
	}

	/**
	 * @return static
	 */
	public function setOuterFilterRendering(bool $outerFilterRendering = true): self
	{
		$this->outerFilterRendering = $outerFilterRendering;

		return $this;
	}

	public function hasOuterFilterRendering(): bool
	{
		return $this->outerFilterRendering;
	}

	/**
	 * @return static
	 * @throws InvalidArgumentException
	 */
	public function setOuterFilterColumnsCount(int $count): self
	{
		$columnsCounts = [1, 2, 3, 4, 6, 12];

		if (!in_array($count, $columnsCounts, true)) {
			throw new InvalidArgumentException(sprintf(
				'Columns count must be one of following values: %s. Value %s given.',
				implode(', ', $columnsCounts),
				$count
			));
		}

		$this->outerFilterColumnsCount = $count;

		return $this;
	}

	public function getOuterFilterColumnsCount(): int
	{
		return $this->outerFilterColumnsCount;
	}

	/**
	 * @return static
	 */
	public function setCollapsibleOuterFilters(bool $collapsibleOuterFilters = true): self
	{
		$this->collapsibleOuterFilters = $collapsibleOuterFilters;

		return $this;
	}

	public function hasCollapsibleOuterFilters(): bool
	{
		return $this->collapsibleOuterFilters;
	}

	/**
	 * Try to restore storage stuff
	 *
	 * @throws DatagridFilterNotFoundException
	 */
	public function findStorageValues(): void
	{
		if (!ArraysHelper::testEmpty($this->filter) || ($this->page !== 1) || $this->sort !== []) {
			return;
		}

		if (!$this->rememberState) {
			return;
		}

		$page = $this->getStorageData('_grid_page');

		if ($page !== null) {
			$this->page = (int) $page;
		}

		$perPage = $this->getStorageData('_grid_perPage');

		if ($perPage !== null) {
			$this->perPage = $perPage;
		}

		$sort = $this->getStorageData('_grid_sort');

		if (is_array($sort) && $sort !== []) {
			$this->sort = $sort;
		}

		foreach ($this->getStorageData('_grid_filters', []) as $key => $value) {
			try {
				$this->getFilter($key);

				$this->filter[$key] = $value;

			} catch (DatagridException) {
				if ($this->strictStorageFilterValues) {
					throw new DatagridFilterNotFoundException(
						sprintf('Storage filter: Filter [%s] not found', $key)
					);
				}
			}
		}

		/**
		 * When column is sorted via custom callback, apply it
		 */
		if ($this->sortCallback === null && $this->sort !== []) {
			foreach (array_keys($this->sort) as $key) {
				try {
					$column = $this->getColumn((string) $key);

				} catch (DatagridColumnNotFoundException) {
					$this->deleteStorageData('_grid_sort');
					$this->sort = [];

					return;
				}

				if ($column->isSortable() && is_callable($column->getSortableCallback())) {
					$this->sortCallback = $column->getSortableCallback();
				}
			}
		}
	}

	/********************************************************************************
	 *                                    EXPORTS *
	 ********************************************************************************/
	public function addExportCallback(
		string $text,
		callable $callback,
		bool $filtered = false
	): Export
	{
		return $this->addToExports(new Export($this, $text, $callback, $filtered));
	}

	public function addExportCsvFiltered(
		string $text,
		string $csvFileName,
		string $outputEncoding = 'utf-8',
		string $delimiter = ';',
		bool $includeBom = false
	): ExportCsv
	{
		return $this->addExportCsv($text, $csvFileName, $outputEncoding, $delimiter, $includeBom, true);
	}

	public function addExportCsv(
		string $text,
		string $csvFileName,
		string $outputEncoding = 'utf-8',
		string $delimiter = ';',
		bool $includeBom = false,
		bool $filtered = false
	): ExportCsv
	{
		$exportCsv = new ExportCsv($this, $text, $csvFileName, $filtered, $outputEncoding, $delimiter, $includeBom);

		$this->addToExports($exportCsv);

		return $exportCsv;
	}

	public function resetExportsLinks(): void
	{
		foreach ($this->exports as $id => $export) {
			$link = new Link($this, 'export!', ['id' => $id]);

			$export->setLink($link);
		}
	}


	/********************************************************************************
	 *                                TOOLBAR BUTTONS *
	 ********************************************************************************/

	/**
	 * @throws DatagridException
	 */
	public function addToolbarButton(
		string $href,
		string $text = '',
		array $params = []
	): ToolbarButton
	{
		if (isset($this->toolbarButtons[$href])) {
			throw new DatagridException(
				sprintf('There is already toolbar button at key [%s] defined.', $href)
			);
		}

		return $this->toolbarButtons[$href] = new ToolbarButton($this, $href, $text, $params);
	}

	/**
	 * @throws DatagridException
	 */
	public function getToolbarButton(string $key): ToolbarButton
	{
		if (!isset($this->toolbarButtons[$key])) {
			throw new DatagridException(
				sprintf('There is no toolbar button at key [%s] defined.', $key)
			);
		}

		return $this->toolbarButtons[$key];
	}

	/**
	 * @return static
	 */
	public function removeToolbarButton(string $key): self
	{
		unset($this->toolbarButtons[$key]);

		return $this;
	}

	/********************************************************************************
	 *                                 GROUP ACTIONS *
	 ********************************************************************************/
	public function addGroupAction(string $title, array $options = []): GroupAction
	{
		return $this->getGroupActionCollection()->addGroupSelectAction($title, $options);
	}

	public function addGroupButtonAction(string $title, ?string $class = null): GroupButtonAction
	{
		return $this->getGroupActionCollection()->addGroupButtonAction($title, $class);
	}

	public function addGroupSelectAction(string $title, array $options = []): GroupAction
	{
		return $this->getGroupActionCollection()->addGroupSelectAction($title, $options);
	}

	public function addGroupMultiSelectAction(string $title, array $options = []): GroupAction
	{
		return $this->getGroupActionCollection()->addGroupMultiSelectAction($title, $options);
	}

	public function addGroupTextAction(string $title): GroupAction
	{
		return $this->getGroupActionCollection()->addGroupTextAction($title);
	}

	public function addGroupTextareaAction(string $title): GroupAction
	{
		return $this->getGroupActionCollection()->addGroupTextareaAction($title);
	}

	public function getGroupActionCollection(): GroupActionCollection
	{
		if ($this->groupActionCollection === null) {
			$this->groupActionCollection = new GroupActionCollection($this);
		}

		return $this->groupActionCollection;
	}

	public function hasGroupActions(): bool
	{
		return $this->groupActionCollection instanceof GroupActionCollection;
	}

	public function shouldShowSelectedRowsCount(): bool
	{
		return $this->showSelectedRowsCount;
	}

	/**
	 * @return static
	 */
	public function setShowSelectedRowsCount(bool $show = true): self
	{
		$this->showSelectedRowsCount = $show;

		return $this;
	}

	/********************************************************************************
	 *                                   HANDLERS *
	 ********************************************************************************/
	public function handlePage(int $page): void
	{
		$this->page = $page;
		$this->saveStorageData('_grid_page', $page);

		$this->reload(['table']);
	}

	/**
	 * @throws DatagridColumnNotFoundException
	 */
	public function handleSort(array $sort): void
	{
		if (count($sort) === 0) {
			$sort = $this->defaultSort;
		}

		foreach (array_keys($sort) as $key) {
			try {
				$column = $this->getColumn($key);

			} catch (DatagridColumnNotFoundException) {
				unset($sort[$key]);

				continue;
			}

			if ($column->sortableResetPagination()) {
				$this->saveStorageData('_grid_page', $this->page = 1);
			}

			if ($column->getSortableCallback() !== null) {
				$this->sortCallback = $column->getSortableCallback();
			}
		}

		$this->saveStorageData('_grid_has_sorted', 1);
		$this->saveStorageData('_grid_sort', $this->sort = $sort);

		$this->reloadTheWholeGrid();
	}

	public function handleResetFilter(): void
	{
		/**
		 * Storage stuff
		 */
		$this->deleteStorageData('_grid_page');

		if ($this->defaultFilterUseOnReset) {
			$this->deleteStorageData('_grid_has_filtered');
		}

		if ($this->defaultSortUseOnReset) {
			$this->deleteStorageData('_grid_has_sorted');
		}

		$this->deleteStorageData('_grid_filters');

		$this->filter = [];

		$this->reloadTheWholeGrid();
	}

	public function handleResetColumnFilter(string $key): void
	{
		$storedFilters = $this->getStorageData('_grid_filters', []);
		if (isset($storedFilters[$key])) {
			unset($storedFilters[$key]);
			$this->saveStorageData('_grid_filters', $storedFilters);
		}

		unset($this->filter[$key]);

		$this->reloadTheWholeGrid();
	}

	/**
	 * @return static
	 */
	public function setColumnReset(bool $reset = true): self
	{
		$this->hasColumnReset = $reset;

		return $this;
	}

	public function hasColumnReset(): bool
	{
		return $this->hasColumnReset;
	}

	/**
	 * @param array<Filter> $filters
	 */
	public function sendNonEmptyFiltersInPayload(array $filters): void
	{
		if (!$this->hasColumnReset()) {
			return;
		}

		$non_empty_filters = [];

		foreach ($filters as $filter) {
			if ($filter->isValueSet()) {
				$non_empty_filters[] = $filter->getKey();
			}
		}

		$this->getPresenterInstance()->payload->non_empty_filters = $non_empty_filters;
	}

	public function handleExport(mixed $id): void
	{
		if (!isset($this->exports[$id])) {
			throw new ForbiddenRequestException();
		}

		if ($this->columnsExportOrder !== []) {
			$this->setColumnsOrder($this->columnsExportOrder);
		}

		$export = $this->exports[$id];

		/**
		 * Invoke possible events
		 */
		$this->onExport($this);

		if ($export->isFiltered()) {
			$sort = $this->sort;
			$filter = $this->assembleFilters();
		} else {
			$sort = [$this->primaryKey => 'ASC'];
			$filter = [];
		}

		if ($this->dataModel === null) {
			throw new DatagridException('You have to set a data source first.');
		}

		$rows = [];

		$items = $this->dataModel->filterData(
			null,
			$this->createSorting($sort, $this->sortCallback),
			$filter
		);

		foreach ($items as $item) {
			$rows[] = new Row($this, $item, $this->getPrimaryKey());
		}

		if ($export instanceof ExportCsv) {
			$export->invoke($rows);
		} else {
			$export->invoke($items);
		}

		if ($export->isAjax()) {
			$this->reload();
		}
	}

	public function handleGetChildren(mixed $parent): void
	{
		if (!is_callable($this->treeViewChildrenCallback)) {
			throw new UnexpectedValueException();
		}

		$this->setDataSource(call_user_func($this->treeViewChildrenCallback, $parent));

		if ($this->getPresenterInstance()->isAjax()) {
			$this->getPresenterInstance()->payload->_datagrid_url = $this->refreshURL;
			$this->getPresenterInstance()->payload->_datagrid_tree = $parent;

			$this->redrawControl('items');

			$this->onRedraw();
		} else {
			$this->getPresenterInstance()->redirect('this');
		}
	}

	public function handleGetItemDetail(mixed $id): void
	{
		$template = $this->getTemplate();

		if (!$template instanceof Template) {
			throw new UnexpectedValueException();
		}

		$template->add('toggle_detail', $id);

		if ($this->itemsDetail === null) {
			throw new UnexpectedValueException();
		}

		$this->redrawItem = [$this->itemsDetail->getPrimaryWhereColumn() => $id];

		if ($this->getPresenterInstance()->isAjax()) {
			$this->getPresenterInstance()->payload->_datagrid_toggle_detail = $id;
			$this->getPresenterInstance()->payload->_datagrid_name = $this->getFullName();
			$this->redrawControl('items');

			/**
			 * Only for nette 2.4
			 */
			if (method_exists($template->getLatte(), 'addProvider')) {
				$this->redrawControl('gridSnippets');
			}

			$this->onRedraw();
		} else {
			$this->getPresenterInstance()->redirect('this');
		}
	}

	public function handleEdit(mixed $id, mixed $key): void
	{
		$column = $this->getColumn($key);
		$request = $this->getPresenterInstance()->getRequest();

		if (!$request instanceof Request) {
			throw new UnexpectedValueException();
		}

		$value = $request->getPost('value');

		// Could be null of course
		if ($column->getEditableCallback() === null) {
			throw new UnexpectedValueException();
		}

		$newValue = $column->getEditableCallback()($id, $value);

		$this->getPresenterInstance()->payload->_datagrid_editable_new_value = $newValue;
		$this->getPresenterInstance()->payload->postGet = true;
		$this->getPresenterInstance()->payload->url = $this->link('this');

		if (!$this->getPresenterInstance()->isControlInvalid(null)) {
			$this->getPresenterInstance()->sendPayload();
		}
	}

	/**
	 * @param array|string[] $snippets
	 */
	public function reload(array $snippets = []): void
	{
		if ($this->getPresenterInstance()->isAjax()) {
			$this->redrawControl('tbody');
			$this->redrawControl('pagination');
			$this->redrawControl('summary');
			$this->redrawControl('thead-group-action');

			/**
			 * manualy reset exports links...
			 */
			$this->resetExportsLinks();
			$this->redrawControl('exports');

			foreach ($snippets as $snippet) {
				$this->redrawControl($snippet);
			}

			$this->getPresenterInstance()->payload->_datagrid_url = $this->refreshURL;
			$this->getPresenterInstance()->payload->_datagrid_name = $this->getFullName();

			$this->onRedraw();
		} else {
			$this->getPresenterInstance()->redirect('this');
		}
	}

	public function reloadTheWholeGrid(): void
	{
		if ($this->getPresenterInstance()->isAjax()) {
			$this->redrawControl('grid');

			$this->getPresenterInstance()->payload->_datagrid_url = $this->refreshURL;
			$this->getPresenterInstance()->payload->_datagrid_name = $this->getFullName();

			$this->onRedraw();
		} else {
			$this->getPresenterInstance()->redirect('this');
		}
	}

	public function handleChangeStatus(string $id, string $key, string $value): void
	{
		if (!isset($this->columns[$key])) {
			throw new DatagridException(sprintf('ColumnStatus[%s] does not exist', $key));
		}

		if (!$this->columns[$key] instanceof ColumnStatus) {
			throw new UnexpectedValueException();
		}

		$this->columns[$key]->onChange($id, $value);
	}

	public function redrawItem(string|int $id, mixed $primaryWhereColumn = null): void
	{
		$this->snippetsSet = true;

		$this->redrawItem = [($primaryWhereColumn ?? $this->primaryKey) => $id];

		$this->redrawControl('items');

		$this->getPresenterInstance()->payload->_datagrid_url = $this->refreshURL;

		$this->onRedraw();
	}

	public function handleShowAllColumns(): void
	{
		$this->deleteStorageData('_grid_hidden_columns');
		$this->saveStorageData('_grid_hidden_columns_manipulated', true);

		$this->redrawControl();

		$this->onShowAllColumns();
		$this->onRedraw();
	}

	public function handleShowDefaultColumns(): void
	{
		$this->deleteStorageData('_grid_hidden_columns');
		$this->saveStorageData('_grid_hidden_columns_manipulated', false);

		$this->redrawControl();

		$this->onShowDefaultColumns();
		$this->onRedraw();
	}

	public function handleShowColumn(string $column): void
	{
		$columns = $this->getStorageData('_grid_hidden_columns');

		if ($columns !== [] && $columns !== null) {
			$pos = array_search($column, $columns, true);

			if ($pos !== false) {
				unset($columns[$pos]);
			}
		}

		$this->saveStorageData('_grid_hidden_columns', $columns);
		$this->saveStorageData('_grid_hidden_columns_manipulated', true);

		$this->redrawControl();

		$this->onColumnShow($column);
		$this->onRedraw();
	}

	public function handleHideColumn(string $column): void
	{
		/**
		 * Store info about hiding a column to storage
		 */
		$columns = $this->getStorageData('_grid_hidden_columns');

		if ($columns === [] || $columns === null) {
			$columns = [$column];
		} elseif (!in_array($column, $columns, true)) {
			array_push($columns, $column);
		}

		$this->saveStorageData('_grid_hidden_columns', $columns);
		$this->saveStorageData('_grid_hidden_columns_manipulated', true);

		$this->redrawControl();

		$this->onColumnHide($column);
		$this->onRedraw();
	}

	public function handleActionCallback(mixed $__key, mixed $__id): void
	{
		$action = $this->getAction($__key);

		if (!($action instanceof ActionCallback)) {
			throw new DatagridException(
				sprintf('Action [%s] does not exist or is not an callback aciton.', $__key)
			);
		}

		$action->onClick($__id);
	}


	/********************************************************************************
	 *                                  PAGINATION *
	 ********************************************************************************/

	/**
	 * @param array|array|int[]|array|string[] $itemsPerPageList
	 * @return static
	 */
	public function setItemsPerPageList(array $itemsPerPageList, bool $includeAll = true): self
	{
		if ($itemsPerPageList === []) {
			throw new InvalidArgumentException('$itemsPerPageList can not be an empty array');
		}

		$this->itemsPerPageList = $itemsPerPageList;

		if ($includeAll) {
			$this->itemsPerPageList[] = 'all';
		}

		return $this;
	}

	/**
	 * @return static
	 */
	public function setDefaultPerPage(int $count): self
	{
		$this->defaultPerPage = $count;

		return $this;
	}

	/**
	 * User may set default "items per page" value, apply it
	 */
	public function findDefaultPerPage(): void
	{
		if ($this->perPage !== null) {
			return;
		}

		if ($this->defaultPerPage !== null) {
			$this->perPage = $this->defaultPerPage;
		}

		$this->saveStorageData('_grid_perPage', $this->perPage);
	}

	public function createComponentPaginator(): DatagridPaginator
	{
		$component = new DatagridPaginator(
			$this->getTranslator(),
			static::$iconPrefix,
			static::$btnSecondaryClass
		);
		$paginator = $component->getPaginator();

		$paginator->setPage($this->page);

		if (is_int($this->getPerPage())) {
			$paginator->setItemsPerPage($this->getPerPage());
		}

		if ($this->customPaginatorTemplate !== null) {
			$component->setTemplateFile($this->customPaginatorTemplate);
		}

		return $component;
	}

	public function getPerPage(): int|string
	{
		$itemsPerPageList = array_keys($this->getItemsPerPageList());

		$perPage = $this->perPage ?? reset($itemsPerPageList);

		if (($perPage !== 'all' && !in_array((int) $this->perPage, $itemsPerPageList, true))
			|| ($perPage === 'all' && !in_array($this->perPage, $itemsPerPageList, true))) {
			$perPage = reset($itemsPerPageList);
		}

		return $perPage === 'all'
			? 'all'
			: (int) $perPage;
	}

	/**
	 * @return array|array|int[]|array|string[]|\Stringable[]
	 */
	public function getItemsPerPageList(): array
	{
		$list = array_flip($this->itemsPerPageList);

		foreach (array_keys($list) as $key) {
			$list[$key] = $key;
		}

		if (array_key_exists('all', $list)) {
			$list['all'] = $this->getTranslator()->translate('contributte_datagrid.all');
		}

		return $list;
	}

	/**
	 * @return static
	 */
	public function setPagination(bool $doPaginate): self
	{
		$this->doPaginate = $doPaginate;

		return $this;
	}

	public function isPaginated(): bool
	{
		return $this->doPaginate;
	}

	public function getPaginator(): ?DatagridPaginator
	{
		if ($this->isPaginated() && $this->perPage !== 'all') {
			return $this['paginator'];
		}

		return null;
	}


	/********************************************************************************
	 *                                     I18N *
	 ********************************************************************************/

	/**
	 * @return static
	 */
	public function setTranslator(Translator $translator): self
	{
		$this->translator = $translator;

		return $this;
	}

	public function getTranslator(): Translator
	{
		if ($this->translator === null) {
			$this->translator = new SimpleTranslator();
		}

		return $this->translator;
	}


	/********************************************************************************
	 *                                 COLUMNS ORDER *
	 ********************************************************************************/

	/**
	 * Set order of datagrid columns
	 *
	 * @param array|string[] $order
	 * @return static
	 */
	public function setColumnsOrder(array $order): self
	{
		$new_order = [];

		foreach ($order as $key) {
			if (isset($this->columns[$key])) {
				$new_order[$key] = $this->columns[$key];
			}
		}

		if (count($new_order) === count($this->columns)) {
			$this->columns = $new_order;
		} else {
			throw new DatagridException('When changing columns order, you have to specify all columns');
		}

		return $this;
	}

	/**
	 * Columns order may be different for export and normal grid
	 *
	 * @param array|string[] $order
	 * @return static
	 */
	public function setColumnsExportOrder(array $order): self
	{
		$this->columnsExportOrder = $order;

		return $this;
	}

	/********************************************************************************
	 *                                SESSION & URL *
	 ********************************************************************************/
	public function getSessionSectionName(): string
	{
		$presenter = $this->getPresenterInstance();

		return $presenter->getName() . ':' . $this->getUniqueId();
	}

	/**
	 * @return static
	 */
	public function setRememberState(bool $remember = true, bool $rememberHideableColumnsState = false): self
	{
		$this->rememberState = $remember;
		$this->rememberHideableColumnsState = $rememberHideableColumnsState;

		return $this;
	}

	/**
	 * @return static
	 */
	public function setRefreshUrl(bool $refresh = true): self
	{
		$this->refreshURL = $refresh;

		return $this;
	}

	public function getStorageData(string $key, mixed $defaultValue = null): mixed
	{
		if ($this->shouldRememberState($key)) {
			return $this->getStateStorage()->loadState($key) ?? $defaultValue;
		}

		return $defaultValue;
	}

	public function saveStorageData(string $key, mixed $value): void
	{
		if ($this->shouldRememberState($key)) {
			$this->getStateStorage()->saveState($key, $value);
		}
	}

	public function deleteStorageData(string $key): void
	{
		$this->getStateStorage()->deleteState($key);
	}

	/********************************************************************************
	 *                                  ITEM DETAIL *
	 ********************************************************************************/

	/**
	 * Get items detail parameters
	 */
	public function getItemsDetail(): ?ItemDetail
	{
		return $this->itemsDetail;
	}

	/**
	 * @param mixed $detail callable|string|bool
	 */
	public function setItemsDetail(mixed $detail = true, ?string $primaryWhereColumn = null): ItemDetail
	{
		if ($this->isSortable()) {
			throw new DatagridException('You can not use both sortable datagrid and items detail.');
		}

		$this->itemsDetail = new ItemDetail($this, $primaryWhereColumn ?? $this->primaryKey);

		if (is_string($detail)) {
			/**
			 * Item detail will be in separate template
			 */
			$this->itemsDetail->setType('template');
			$this->itemsDetail->setTemplate($detail);

		} elseif (is_callable($detail)) {
			/**
			 * Item detail will be rendered via custom callback renderer
			 */
			$this->itemsDetail->setType('renderer');
			$this->itemsDetail->setRenderer($detail);

		} elseif ($detail === true) {
			/**
			 * Item detail will be rendered probably via block #detail
			 */
			$this->itemsDetail->setType('block');

		} else {
			throw new DatagridException('::setItemsDetail() can be called either with no parameters or with parameter = template path or callable renderer.');
		}

		return $this->itemsDetail;
	}

	/**
	 * @return static
	 */
	public function setItemsDetailForm(callable $callableSetContainer): self
	{
		if ($this->itemsDetail instanceof ItemDetail) {
			$this->itemsDetail->setForm(
				new ItemDetailForm($callableSetContainer)
			);

			return $this;
		}

		throw new DatagridException('Please set the ItemDetail first.');
	}

	public function getItemDetailForm(): ?Container
	{
		if ($this->itemsDetail instanceof ItemDetail) {
			return $this->itemsDetail->getForm();
		}

		return null;
	}

	/********************************************************************************
	 *                                ROW PRIVILEGES *
	 ********************************************************************************/
	public function allowRowsGroupAction(callable $condition): void
	{
		$this->rowConditions['group_action'] = $condition;
	}

	public function allowRowsInlineEdit(callable $condition): void
	{
		$this->rowConditions['inline_edit'] = $condition;
	}

	public function allowRowsAction(string $key, callable $condition): void
	{
		$this->rowConditions['action'][$key] = $condition;
	}

	/**
	 * @throws DatagridException
	 */
	public function allowRowsMultiAction(
		string $multiActionKey,
		string $actionKey,
		callable $condition
	): void
	{
		if (!isset($this->actions[$multiActionKey])) {
			throw new DatagridException(
				sprintf('There is no action at key [%s] defined.', $multiActionKey)
			);
		}

		if (!$this->actions[$multiActionKey] instanceof MultiAction) {
			throw new DatagridException(
				sprintf('Action at key [%s] is not a MultiAction.', $multiActionKey)
			);
		}

		$this->actions[$multiActionKey]->setRowCondition($actionKey, $condition);
	}

	public function getRowCondition(string $name, ?string $key = null): bool|callable
	{
		if (!isset($this->rowConditions[$name])) {
			return false;
		}

		$condition = $this->rowConditions[$name];

		if ($key === null) {
			return $condition;
		}

		return $condition[$key] ?? false;
	}

	/********************************************************************************
	 *                               COLUMN CALLBACK *
	 ********************************************************************************/
	public function addColumnCallback(string $key, callable $callback): void
	{
		$this->columnCallbacks[$key] = $callback;
	}

	public function getColumnCallback(string $key): ?callable
	{
		return $this->columnCallbacks[$key] ?? null;
	}

	/********************************************************************************
	 *                                 INLINE EDIT *
	 ********************************************************************************/
	public function addInlineEdit(?string $primaryWhereColumn = null): InlineEdit
	{
		$this->inlineEdit = new InlineEdit($this, $primaryWhereColumn ?? $this->primaryKey);

		return $this->inlineEdit;
	}

	public function getInlineEdit(): ?InlineEdit
	{
		return $this->inlineEdit;
	}

	public function handleShowInlineAdd(): void
	{
		if ($this->inlineAdd !== null) {
			$this->inlineAdd->setShouldBeRendered(true);
		}

		$presenter = $this->getPresenterInstance();

		if ($presenter->isAjax()) {
			$presenter->payload->_datagrid_inline_adding = true;
			$presenter->payload->_datagrid_name = $this->getFullName();

			$this->redrawControl('tbody');

			$this->onRedraw();
		}
	}

	public function handleInlineEdit(mixed $id): void
	{
		if ($this->inlineEdit !== null) {
			$this->inlineEdit->setItemId($id);

			$primaryWhereColumn = $this->inlineEdit->getPrimaryWhereColumn();

			$filterContainer = $this['filter'];
			$inlineEditContainer = $filterContainer['inline_edit'];

			if (!$inlineEditContainer instanceof Container) {
				throw new UnexpectedValueException();
			}

			$inlineEditContainer->addHidden('_id', $id);
			$inlineEditContainer->addHidden('_primary_where_column', $primaryWhereColumn);

			$presenter = $this->getPresenterInstance();

			if ($presenter->isAjax()) {
				$presenter->payload->_datagrid_inline_editing = true;
				$presenter->payload->_datagrid_name = $this->getFullName();
			}

			$this->redrawItem($id, $primaryWhereColumn);
		}
	}

	/********************************************************************************
	 *                                  INLINE ADD *
	 ********************************************************************************/
	public function addInlineAdd(): InlineAdd
	{
		$this->inlineAdd = new InlineAdd($this);

		$this->inlineAdd
			->setTitle('contributte_datagrid.add')
			->setIcon('plus');

		return $this->inlineAdd;
	}

	public function getInlineAdd(): ?InlineAdd
	{
		return $this->inlineAdd;
	}


	/********************************************************************************
	 *                               COLUMNS HIDING *
	 ********************************************************************************/

	/**
	 * Can datagrid hide colums?
	 */
	public function canHideColumns(): bool
	{
		return $this->canHideColumns;
	}

	/**
	 * Order Grid to set columns hideable.
	 *
	 * @return static
	 */
	public function setColumnsHideable(bool $columnsHideable = true): self
	{
		$this->canHideColumns = $columnsHideable;

		return $this;
	}

	/********************************************************************************
	 *                                COLUMNS SUMMARY *
	 ********************************************************************************/
	public function hasColumnsSummary(): bool
	{
		return $this->columnsSummary instanceof ColumnsSummary;
	}

	/**
	 * @param array|string[] $columns
	 */
	public function setColumnsSummary(array $columns, ?callable $rowCallback = null): ColumnsSummary
	{
		if ($this->hasSomeAggregationFunction()) {
			throw new DatagridException('You can use either ColumnsSummary or AggregationFunctions');
		}

		$this->columnsSummary = new ColumnsSummary($this, $columns, $rowCallback);

		return $this->columnsSummary;
	}

	public function getColumnsSummary(): ?ColumnsSummary
	{
		return $this->columnsSummary;
	}


	/********************************************************************************
	 *                                   INTERNAL *
	 ********************************************************************************/

	/**
	 * Gets component's full name in component tree
	 *
	 * @throws DatagridHasToBeAttachedToPresenterComponentException
	 */
	public function getFullName(): string
	{
		if ($this->componentFullName === null) {
			throw new DatagridHasToBeAttachedToPresenterComponentException('Datagrid needs to be attached to presenter in order to get its full name.');
		}

		return $this->componentFullName;
	}

	/**
	 * Tell grid filters to by submitted automatically
	 *
	 * @return static
	 */
	public function setAutoSubmit(bool $autoSubmit = true): self
	{
		$this->autoSubmit = $autoSubmit;

		return $this;
	}

	public function hasAutoSubmit(): bool
	{
		return $this->autoSubmit;
	}

	public function getFilterSubmitButton(): SubmitButton
	{
		if ($this->hasAutoSubmit()) {
			throw new DatagridException('Datagrid has auto-submit. Turn it off before setting filter submit button.');
		}

		if ($this->filterSubmitButton === null) {
			$this->filterSubmitButton = new SubmitButton($this);
		}

		return $this->filterSubmitButton;
	}


	/********************************************************************************
	 *                                   INTERNAL *
	 ********************************************************************************/

	/**
	 * @internal
	 */
	public function getColumnsCount(): int
	{
		$count = count($this->getColumns());

		if ($this->actions !== []
			|| $this->isSortable()
			|| $this->getItemsDetail() !== null
			|| $this->getInlineEdit() !== null
			|| $this->getInlineAdd() !== null) {
			$count++;
		}

		if ($this->hasGroupActions()) {
			$count++;
		}

		return $count;
	}

	/**
	 * @internal
	 */
	public function getPrimaryKey(): string
	{
		return $this->primaryKey;
	}

	/**
	 * @return array<Column>
	 * @internal
	 */
	public function getColumns(): array
	{
		$return = $this->columns;

		try {
			$this->getParentComponent();

			if (! (bool) $this->getStorageData('_grid_hidden_columns_manipulated', false)) {
				$columns_to_hide = [];

				foreach ($this->columns as $key => $column) {
					if ($column->getDefaultHide()) {
						$columns_to_hide[] = $key;
					}
				}

				if ($columns_to_hide !== []) {
					$this->saveStorageData('_grid_hidden_columns', $columns_to_hide);
					$this->saveStorageData('_grid_hidden_columns_manipulated', true);
				}
			}

			$hidden_columns = $this->getStorageData('_grid_hidden_columns', []);

			foreach ($hidden_columns ?? [] as $column) {
				if (isset($this->columns[$column])) {
					$this->columnsVisibility[$column] = [
						'visible' => false,
					];

					unset($return[$column]);
				}
			}
		} catch (DatagridHasToBeAttachedToPresenterComponentException) {
			// No need to worry
		}

		return $return;
	}

	/**
	 * @internal
	 */
	public function getColumnsVisibility(): array
	{
		$return = $this->columnsVisibility;

		foreach (array_keys($this->columnsVisibility) as $key) {
			$return[$key]['column'] = $this->columns[$key];
		}

		return $return;
	}

	/**
	 * @internal
	 */
	public function getParentComponent(): Component
	{
		$parent = parent::getParent();

		if (!$parent instanceof Component) {
			throw new DatagridHasToBeAttachedToPresenterComponentException(
				sprintf(
					'Datagrid is attached to: "%s", but instance of %s is needed.',
					($parent !== null ? $parent::class : 'null'),
					Component::class
				)
			);
		}

		return $parent;
	}

	/**
	 * @internal
	 * @throws UnexpectedValueException
	 */
	public function getSortableParentPath(): string
	{
		if ($this->getParentComponent() instanceof IPresenter) {
			return '';
		}

		$presenter = $this->getParentComponent()->lookupPath(IPresenter::class, false);

		if ($presenter === null) {
			throw new UnexpectedValueException(
				sprintf('%s needs %s', self::class, IPresenter::class)
			);
		}

		return $presenter;
	}

	/**
	 * Some of datagrid columns may be hidden by default
	 *
	 * @internal
	 * @return static
	 */
	public function setSomeColumnDefaultHide(bool $defaultHide): self
	{
		$this->someColumnDefaultHide = $defaultHide;

		return $this;
	}

	/**
	 * Are some of columns hidden bydefault?
	 *
	 * @internal
	 */
	public function hasSomeColumnDefaultHide(): bool
	{
		return $this->someColumnDefaultHide;
	}

	/**
	 * Simply refresh url
	 *
	 * @internal
	 */
	public function handleRefreshState(): void
	{
		$this->findStorageValues();
		$this->findDefaultFilter();
		$this->findDefaultSort();
		$this->findDefaultPerPage();

		$this->getPresenterInstance()->payload->_datagrid_url = $this->refreshURL;
		$this->redrawControl('non-existing-snippet');
	}

	/**
	 * @internal
	 */
	public function setCustomPaginatorTemplate(string $templateFile): void
	{
		$this->customPaginatorTemplate = $templateFile;
	}

	protected function createSorting(array $sort, ?callable $sortCallback = null): Sorting
	{
		foreach ($sort as $key => $order) {
			unset($sort[$key]);

			if ($order !== 'ASC' && $order !== 'DESC') {
				continue;
			}

			try {
				$column = $this->getColumn($key);

			} catch (DatagridColumnNotFoundException) {
				continue;
			}

			$sort[$column->getSortingColumn()] = $order;
		}

		if ($sortCallback === null && isset($column)) {
			$sortCallback = $column->getSortableCallback();
		}

		return new Sorting($sort, $sortCallback);
	}

	/**
	 * @throws DatagridException
	 */
	protected function addColumn(string $key, Column $column): Column
	{
		if (isset($this->columns[$key])) {
			throw new DatagridException(
				sprintf('There is already column at key [%s] defined.', $key)
			);
		}

		$this->onColumnAdd($key, $column);

		$this->columnsVisibility[$key] = ['visible' => true];

		return $this->columns[$key] = $column;
	}

	/**
	 * Check whether given key already exists in $this->filters
	 *
	 * @throws DatagridException
	 */
	protected function addActionCheck(string $key): void
	{
		if (isset($this->actions[$key])) {
			throw new DatagridException(
				sprintf('There is already action at key [%s] defined.', $key)
			);
		}
	}

 /********************************************************************************
  *                                    FILTERS *
  ********************************************************************************/

	/**
	 * Check whether given key already exists in $this->filters
	 *
	 * @throws DatagridException
	 */
	protected function addFilterCheck(string $key): void
	{
		if (isset($this->filters[$key])) {
			throw new DatagridException(
				sprintf('There is already action at key [%s] defined.', $key)
			);
		}
	}

	protected function addToExports(Export $export): Export
	{
		$id = count($this->exports) > 0 ? count($this->exports) + 1 : 1;

		$link = new Link($this, 'export!', ['id' => $id]);

		$export->setLink($link);

		return $this->exports[$id] = $export;
	}

	private function getPresenterInstance(): Presenter
	{
		return $this->getPresenter();
	}

	private function shouldRememberState(string $key): bool
	{
		return $this->rememberState ||
			($this->rememberHideableColumnsState && in_array($key, self::HIDEABLE_COLUMNS_STORAGE_KEYS, true));
	}

}
