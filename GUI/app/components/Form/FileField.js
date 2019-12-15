// @flow
import React from 'react';
import has from 'lodash/has';
import { useField, useFormikContext } from 'formik';
import { makeStyles } from '@material-ui/core/styles';
import { remote } from 'electron';
import Icon from '@material-ui/core/Icon';
import IconButton from '@material-ui/core/IconButton';
import Input from '@material-ui/core/Input';
import InputLabel from '@material-ui/core/InputLabel';
import InputAdornment from '@material-ui/core/InputAdornment';
import FormHelperText from '@material-ui/core/FormHelperText';
import FormControl from '@material-ui/core/FormControl';

const useStyles = makeStyles(theme => ({
  formControl: {
    margin: theme.spacing(1),
    minWidth: 120
  }
}));

export type FileFilter = {
  name: string,
  extensions: string[]
};

export type DialogOptions = {
  title?: string,
  buttonLabel?: string,
  filters?: FileFilter[],
  message?: string,
  properties?: Array<'openFile' | 'openDirectory' | 'multiSelections'>
};

export type FileFieldProps = {
  label: string,
  name: string,
  required?: boolean,
  dialogOptions?: DialogOptions,
  separator?: string
};

FileField.defaultProps = {
  required: false,
  dialogOptions: {},
  separator: ', '
};

export default function FileField({
  label,
  required,
  dialogOptions,
  separator,
  ...props
}: FileFieldProps) {
  const classes = useStyles();
  const { setFieldValue } = useFormikContext();
  const [{ name, onBlur, onChange, value }, { error, touched }] = useField(
    props
  );
  let multiple = false;
  if (has(dialogOptions, 'properties')) {
    if (dialogOptions.properties.includes('multiSelections')) {
      multiple = true;
    }
  }
  const handleClick = async () => {
    const { canceled, filePaths } = await remote.dialog.showOpenDialog(
      remote.getCurrentWindow(),
      dialogOptions
    );
    if (!canceled) {
      if (filePaths) {
        setFieldValue(
          name,
          multiple ? filePaths.join(separator) : filePaths.shift()
        );
      }
    }
  };
  const handleMouseDown = event => {
    event.preventDefault();
  };
  return (
    <FormControl
      className={classes.formControl}
      fullWidth
      error={!!(touched && error)}
      required={required}
    >
      <InputLabel>{label}</InputLabel>
      <Input
        name={name}
        type="text"
        value={value}
        onChange={onChange}
        onBlur={onBlur}
        endAdornment={
          <InputAdornment position="end">
            <IconButton onClick={handleClick} onMouseDown={handleMouseDown}>
              <Icon className="fas fa-ellipsis-h" />
            </IconButton>
          </InputAdornment>
        }
      />
      {touched && error ? <FormHelperText>{error}</FormHelperText> : null}
    </FormControl>
  );
}