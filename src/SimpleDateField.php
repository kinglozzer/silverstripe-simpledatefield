<?php

namespace Bigfork\SilverStripeSimpleDateField;

use DateTime;
use IntlDateFormatter;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\FormMessage;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\ValidationResult;

class SimpleDateField extends FormField
{
    const DMY = 1;
    const YMD = 2;
    const MDY = 3;

    protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_DATE;

    /**
     * @var FieldList
     */
    protected $children;

    /**
     * @var FormField
     */
    protected $dayField;

    /**
     * @var FormField
     */
    protected $monthField;

    /**
     * @var FormField
     */
    protected $yearField;

    /**
     * @var string
     */
    protected $rawValue;

    /**
     * @param string $name
     * @param string|null $title
     * @param string|null $value
     * @param int $order
     */
    public function __construct($name, $title = null, $value = null, $order = self::DMY)
    {
        $this->dayField = TextField::create("{$name}[_Day]", 'Day')
            ->setInputType('number')
            ->setAttribute('pattern', '[0-9]*');
        $this->monthField = TextField::create("{$name}[_Month]", 'Month')
            ->setInputType('number')
            ->setAttribute('pattern', '[0-9]*');
        $this->yearField = TextField::create("{$name}[_Year]", 'Year')
            ->setInputType('number')
            ->setAttribute('pattern', '[0-9]*');

        if ($order === self::YMD) {
            $children = [$this->yearField, $this->monthField, $this->dayField];
        } else if ($order === self::MDY) {
            $children = [$this->monthField, $this->dayField, $this->yearField];
        } else {
            $children = [$this->dayField, $this->monthField, $this->yearField];
        }

        $this->children = FieldList::create($children);
        parent::__construct($name, $title, $value);
    }

    /**
     * @param mixed $value
     * @param null $data
     * @return $this
     */
    public function setValue($value, $data = null)
    {
        $timestamp = $this->tidyInternal($value);
        if (!$timestamp) {
            $this->value = null;
            return $this;
        }

        $this->value = date('Y-m-d', $timestamp);
        $this->yearField->setValue(date('Y', $timestamp));
        $this->monthField->setValue(date('m', $timestamp));
        $this->dayField->setValue(date('d', $timestamp));

        return $this;
    }

    /**
     * @param mixed $value
     * @param null $data
     * @return $this
     */
    public function setSubmittedValue($value, $data = null)
    {
        $this->rawValue = $value;

        $this->value = null;
        if (is_array($value)) {
            $year = $value['_Year'] ?? '';
            $month = $value['_Month'] ?? '';
            $day = $value['_Day'] ?? '';

            // todo - make automatic year 4-digit conversion optional once DBDate accepts years <1000:
            // https://github.com/silverstripe/silverstripe-framework/issues/9133
            $year = str_pad($year, 4, '19', STR_PAD_LEFT);
            $month = str_pad($month, 2, '0', STR_PAD_LEFT);
            $day = str_pad($day, 2, '0', STR_PAD_LEFT);

            $this->yearField->setValue($year);
            $this->monthField->setValue($month);
            $this->dayField->setValue($day);

            $date = "{$year}-{$month}-{$day}";
            if ($this->isValidISODate($date)) {
                $this->setValue($date);
            }
        }

        return $this;
    }

    /**
     * @param array|FieldList $children
     * @return $this
     */
    public function setChildren($children)
    {
        if (is_array($children)) {
            $children = FieldList::create($children);
        }

        $this->children = $children;
        return $this;
    }

    /**
     * @return FieldList
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * @param FormField $field
     * @return $this
     */
    public function setDayField(FormField $field)
    {
        $this->dayField = $field;
        return $this;
    }

    /**
     * @return FormField
     */
    public function getDayField()
    {
        return $this->dayField;
    }

    /**
     * @param FormField $field
     * @return $this
     */
    public function setMonthField(FormField $field)
    {
        $this->monthField = $field;
        return $this;
    }

    /**
     * @return FormField
     */
    public function getMonthField()
    {
        return $this->monthField;
    }

    /**
     * @param FormField $field
     * @return $this
     */
    public function setYearField(FormField $field)
    {
        $this->yearField = $field;
        return $this;
    }

    /**
     * @return FormField
     */
    public function getYearField()
    {
        return $this->yearField;
    }

    public function validate($validator)
    {
        // Don't attempt to validate empty fields
        if ($this->rawValue === null) {
            return true;
        }

        // Value was submitted, but is invalid
        if (empty($this->value) || !$this->isValidISODate($this->value)) {
            $year = (int)$this->getYearField()->Value();
            $month = (int)$this->getMonthField()->Value();
            $day = (int)$this->getDayField()->Value();
            if ($month) {
                if ($month > 12) {
                    $validator->validationError(
                        $this->name,
                        '[_Month] Month invalid'
                    );
                } else if ($year && function_exists('cal_days_in_month')) {
                    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
                    if ($day > $daysInMonth) {
                        $validator->validationError(
                            $this->name,
                            '[_Day] Day invalid'
                        );
                    }
                }
            }

            $validator->validationError(
                $this->name,
                'Please enter a valid date'
            );
            return false;
        }

        return true;
    }

    public function setMessage(
        $message,
        $messageType = ValidationResult::TYPE_ERROR,
        $messageCast = ValidationResult::CAST_TEXT
    ) {
        if (strpos($message, '[_Year]') === 0) {
            $this->monthField->setMessage(substr($message, 7), $messageType, $messageCast);
            return $this;
        } if (strpos($message, '[_Month]') === 0) {
            $this->monthField->setMessage(substr($message, 8), $messageType, $messageCast);
            return $this;
        } else if (strpos($message, '[_Day]') === 0) {
            $this->dayField->setMessage(substr($message, 6), $messageType, $messageCast);
            return $this;
        }

        return parent::setMessage($message, $messageType, $messageCast);
    }

    /**
     * Get a date formatter for the ISO 8601 format
     *
     * @return IntlDateFormatter
     */
    protected function getInternalFormatter()
    {
        $formatter = IntlDateFormatter::create(
            DBDate::ISO_LOCALE,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::NONE
        );
        $formatter->setLenient(false);
        $formatter->setPattern(DBDate::ISO_DATE);

        return $formatter;
    }

    /**
     * @param string $date
     * @return int|null
     */
    protected function tidyInternal($date)
    {
        if (!$date) {
            return null;
        }

        // Assume date is provided in correct format (Y-m-d)
        $formatter = $this->getInternalFormatter();
        $timestamp = $formatter->parse($date);
        if ($timestamp === false) {
            // Fallback to strtotime
            $timestamp = strtotime($date, DBDatetime::now()->getTimestamp());
            if ($timestamp === false) {
                return null;
            }
        }

        return $timestamp;
    }

    /**
     * @param string $date
     * @return bool
     */
    protected function isValidISODate($date)
    {
        $datetime = DateTime::createFromFormat('Y-m-d', $date);
        return $datetime && $datetime->format('Y-m-d') === $date;
    }
}
